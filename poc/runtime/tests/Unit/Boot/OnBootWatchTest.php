<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Boot;

use PeakSim\Runtime\Boot\OnBootViolation;
use PeakSim\Runtime\Boot\OnBootWatch;
use PeakSim\Runtime\Measure\Profile;
use PeakSim\Runtime\Tests\Unit\Boot\Stubs\FakeApp;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/FakeApp.php';

/**
 * Decision 5 of the order: a profile's `on_boot` is allowed in the proof of concept on two conditions that are held by
 * a test, not by a promise: it does not touch the database, and it does not take the fakes off.
 */
final class OnBootWatchTest extends TestCase
{
    private const QUERY_EVENT = 'Illuminate\\Database\\Events\\QueryExecuted';

    private static function watch(FakeApp $app, ?\Closure $fingerprint = null): OnBootWatch
    {
        return new OnBootWatch($app, $fingerprint ?? static fn (): array => ['mail' => 1, 'queue' => 2, 'http_stray_blocked' => true]);
    }

    public function testAHookThatOnlySetsConfigurationPasses(): void
    {
        $app = new FakeApp();

        self::watch($app)->run(static function (object $app): void {
            $app['config']->set('x.y', 1);
        });

        $this->assertSame(1, $app->config()->get('x.y'));
    }

    public function testAHookThatSendsAQueryIsStopped(): void
    {
        $app = new FakeApp();

        $this->expectException(OnBootViolation::class);
        $this->expectExceptionMessage('database');
        self::watch($app)->run(static function (object $app): void {
            $app['events']->fire(self::QUERY_EVENT, (object) ['sql' => 'select 1']);
        });
    }

    public function testAHookThatOpensATransactionIsStopped(): void
    {
        $app = new FakeApp();

        $this->expectException(OnBootViolation::class);
        $this->expectExceptionMessage('database');
        self::watch($app)->run(static function (object $app): void {
            $app['db']->level = 1;
        });
    }

    public function testAHookThatReplacesAFakeIsStopped(): void
    {
        $app = new FakeApp();
        $calls = 0;
        $fingerprint = static function () use (&$calls): array {
            return ['mail' => ++$calls > 1 ? 99 : 1, 'queue' => 2];
        };

        $this->expectException(OnBootViolation::class);
        $this->expectExceptionMessage('fake');
        self::watch($app, $fingerprint)->run(static function (object $app): void {
        });
    }

    public function testAQueryAfterTheHookHasReturnedIsNotCounted(): void
    {
        $app = new FakeApp();
        self::watch($app)->run(static function (object $app): void {
        });

        $app['events']->fire(self::QUERY_EVENT, (object) ['sql' => 'select 1']);
        $this->addToAssertionCount(1); // no exception: the watch is over
    }

    public function testTheProfileLoaderRunsAHookOfAStartedAppUnderTheWatch(): void
    {
        $app = new FakeApp();
        $profile = ['on_boot' => static function (object $app): void {
            $app['db']->level = 2;
        }];

        $this->expectException(OnBootViolation::class);
        Profile::onBoot($profile, $app, self::watch($app));
    }

    public function testTheRealFingerprintNamesTheThreeFakesAndTheOutboundBlock(): void
    {
        $names = \PeakSim\Runtime\Boot\Fakes::fingerprintKeys();

        $this->assertSame(['http_stray_blocked', 'mail', 'notification', 'queue'], $names);
    }
}
