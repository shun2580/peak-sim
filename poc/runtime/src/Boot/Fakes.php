<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Boot;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PeakSim\Runtime\Config\MeasureRefused;

/**
 * Safety condition 5: side effects are fake, and the fakes are checked after they are in place.
 *
 *  - Mail, Notification, Queue: Laravel's own fakes. Queued listeners and jobs are stored, not run, so the queries
 *    they would run are not counted (stated in the report).
 *  - Events are NOT replaced: observers and listeners run, and their queries are counted.
 *  - Http: every request fails with a connection error, and the host is written to the ledger. Nothing is let through.
 *  - Storage: local disks are Laravel's fake (under the scratch dir); a disk with an outside driver (s3, ftp, ...)
 *    is replaced by a local one first.
 *  - Cache and session are the `array` driver, so every process starts with an empty cache.
 *
 * Needs the target app's Laravel; it is loaded only when the app is booted.
 */
final class Fakes
{
    /** @return array<string, mixed> what was put in place (the evidence) */
    public static function install(object $app, ExternalCallLedger $ledger, ScratchDir $scratch): array
    {
        $config = $app['config'];
        $report = [];

        // Settings the app may have fixed in its config files, whatever the environment says.
        $config->set('cache.default', 'array');
        if ($config->get('cache.stores.array') === null) {
            $config->set('cache.stores.array', ['driver' => 'array', 'serialize' => false]);
        }
        $config->set('session.driver', 'array');
        $config->set('queue.default', 'sync');
        $config->set('mail.default', 'array');
        if ($config->get('mail.mailers.array') === null) {
            $config->set('mail.mailers.array', ['transport' => 'array']);
        }
        if ($config->has('broadcasting.default')) {
            $config->set('broadcasting.default', 'null');
        }
        if ($config->has('scout.driver')) {
            $config->set('scout.driver', 'null');
        }
        $cache = $app['cache'];
        if (method_exists($cache, 'forgetDriver')) {
            $cache->forgetDriver();
        }
        if ($app->bound('session') && method_exists($app['session'], 'forgetDrivers')) {
            $app['session']->forgetDrivers();
        }

        $store = $cache->store()->getStore();
        $storeClass = get_class($store);
        if (!str_ends_with($storeClass, 'ArrayStore')) {
            throw new MeasureRefused(sprintf('refused: the cache is %s, not the array store', $storeClass));
        }
        $report['cache_store'] = $storeClass;
        $report['session_driver'] = $config->get('session.driver');

        Mail::fake();
        Notification::fake();
        Queue::fake();
        $report['mail'] = get_class(Mail::getFacadeRoot());
        $report['notification'] = get_class(Notification::getFacadeRoot());
        $report['queue'] = get_class(Queue::getFacadeRoot());

        Http::preventStrayRequests();
        Http::fake(static function (HttpRequest $request) use ($ledger): never {
            $ledger->add($request->url());
            throw new ConnectionException('peak-sim: blocked an outbound request');
        });
        $report['http'] = 'every request fails with a connection error';

        $report['disks_replaced'] = self::fakeStorage($app, $scratch);

        return $report;
    }

    /** @return list<string> disks that had an outside driver and were replaced by a local one */
    private static function fakeStorage(object $app, ScratchDir $scratch): array
    {
        $config = $app['config'];
        $disks = (array) $config->get('filesystems.disks', []);
        $replaced = [];
        foreach ($disks as $name => $settings) {
            $driver = (string) (((array) $settings)['driver'] ?? '');
            if ($driver !== 'local' && $driver !== 'scoped') {
                $replaced[] = (string) $name;
                $config->set('filesystems.disks.' . $name, ['driver' => 'local', 'root' => $scratch->storagePath() . '/app/replaced-' . $name, 'throw' => false]);
            }
            $app['filesystem']->forgetDisk((string) $name);
        }
        $names = array_unique(['local', 'public', (string) $config->get('filesystems.default', 'local')]);
        foreach ($names as $name) {
            Storage::fake($name);
        }

        return $replaced;
    }
}
