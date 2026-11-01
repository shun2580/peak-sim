<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Docker;

use PeakSim\Runtime\Tests\Docker\Support\DockerCase;
use PHPUnit\Framework\Attributes\Group;
use PeakSim\Runtime\Tests\Docker\Support\ProbeApp;

/**
 * W2 acceptance on the fixture app: it starts read-only, one request is counted, the fakes work, the cache is the
 * array store, every connection is the measurement database, and a write to the app fails.
 */
#[Group('in-stack')]
final class AppBootDockerTest extends DockerCase
{
    private const SCALE = 100;
    private const SCALE_BIG = 1000;
    private const APP = '/app';

    public static function setUpBeforeClass(): void
    {
        self::requireInsideStack();
        self::prepare(self::SCALE, [20, 20, 20, 3]);
        self::prepare(self::SCALE_BIG, [20, 20, 200, 3]);
    }

    public static function tearDownAfterClass(): void
    {
        self::drop(self::SCALE);
        self::drop(self::SCALE_BIG);
    }

    public function testEagerRouteIsRequestedThreeTimesAndItsQueriesAreCounted(): void
    {
        [$exit, $attempts, $err] = self::measure(['--scale=' . self::SCALE, '--uri=/ok/eager']);

        self::assertSame(0, $exit, $err);
        self::assertCount(3, $attempts);
        foreach ($attempts as $i => $attempt) {
            self::assertSame($i + 1, $attempt['attempt']);
            self::assertSame(200, $attempt['status']);
            self::assertSame('ok', $attempt['class']);
            self::assertSame(2, $attempt['queries'], 'with() loads orders and customers: two queries');
            self::assertSame(['mysql' => 2], $attempt['by_connection']);
            self::assertSame(['customers', 'orders'], $attempt['tables']);
            self::assertGreaterThan(0, $attempt['bytes']);
            self::assertSame(self::SCALE, $attempt['scale']);
            self::assertFalse($attempt['gate_bypass']);
        }
    }

    public function testTheCountGrowsWithTheDataForAnNPlusOneRouteAndStaysFlatForAnEagerOne(): void
    {
        $count = static function (int $scale, string $uri): int {
            [$exit, $attempts, $err] = self::measure(['--scale=' . $scale, '--uri=' . $uri, '--phases=cold']);
            self::assertSame(0, $exit, $err);

            return $attempts[0]['queries'];
        };

        self::assertSame($count(self::SCALE, '/ok/eager'), $count(self::SCALE_BIG, '/ok/eager'));
        self::assertGreaterThan($count(self::SCALE, '/ng/n-plus-one') * 3, $count(self::SCALE_BIG, '/ng/n-plus-one'));
    }

    public function testAQueryGivenToARequestReachesTheAppAsItsInput(): void
    {
        [$exit, $first, $err] = self::measure(['--scale=' . self::SCALE, '--uri=/ok/paginate', '--query=page=1', '--phases=cold']);
        self::assertSame(0, $exit, $err);
        [$exit, $second, $err] = self::measure(['--scale=' . self::SCALE, '--uri=/ok/paginate', '--query=page=2', '--phases=cold']);
        self::assertSame(0, $exit, $err);
        [$exit, $none, $err] = self::measure(['--scale=' . self::SCALE, '--uri=/ok/paginate', '--phases=cold']);
        self::assertSame(0, $exit, $err);

        self::assertSame('ok', $first[0]['class']);
        self::assertSame('ok', $second[0]['class']);
        self::assertNotSame($first[0]['bytes'], $second[0]['bytes'], 'page 2 is another answer than page 1: the app read the query');
        self::assertSame($first[0]['bytes'], $none[0]['bytes'], 'no query is page 1, as it always was');
    }

    public function testATokenInTheQueryIsMadeFromTheRunsAppKeyAndTheKeyIsNeverPrinted(): void
    {
        $key = \PeakSim\Runtime\Boot\AppBooter::newAppKey();
        $derived = \PeakSim\Runtime\Route\RequestCredentials::derive($key, 'subsonic', 1);

        [$exit, $attempts, $err] = self::measure(['--scale=' . self::SCALE, '--uri=/ok/paginate', '--query=token={key:subsonic:1}', '--phases=cold'], ['PEAK_MEASURE_APP_KEY' => $key]);

        self::assertSame(0, $exit, $err);
        self::assertSame('ok', $attempts[0]['class']);
        self::assertStringNotContainsString($derived, json_encode($attempts, JSON_THROW_ON_ERROR) . $err, 'the key is not in the output');
    }

    public function testATokenWithoutTheRunsAppKeyIsNotSentAndSaysWhyWithoutAValue(): void
    {
        [$exit, $attempts, $err] = self::measure(['--scale=' . self::SCALE, '--uri=/ok/paginate', '--query=token={key:subsonic:1}', '--phases=cold'], ['PEAK_MEASURE_APP_KEY' => '']);

        self::assertSame(0, $exit, $err);
        self::assertCount(1, $attempts);
        self::assertSame('unfilled_param', $attempts[0]['class']);
        self::assertStringContainsString('APP_KEY', (string) $attempts[0]['detail']);
        self::assertNull($attempts[0]['queries'], 'no request was made');
    }

    public function testTheAppIsReadOnlyAndNothingChangedAfterARunThatWrites(): void
    {
        $before = self::snapshot(self::APP);

        // a write route, with the session and token it needs, then a read route
        [$exit, , $err] = self::measure(['--scale=' . self::SCALE, '--uri=/ok/bulk', '--method=POST', '--csrf']);
        self::assertSame(0, $exit, $err);
        [$exit, , $err] = self::measure(['--scale=' . self::SCALE, '--uri=/ok/eager']);
        self::assertSame(0, $exit, $err);

        self::assertSame($before, self::snapshot(self::APP), 'the app tree must be byte-for-byte what it was');
    }

    public function testAWriteIntoTheAppFailsBecauseItIsMountedReadOnly(): void
    {
        $result = @file_put_contents(self::APP . '/peak-probe-write.txt', 'x');
        self::assertFalse($result);
        self::assertStringContainsString('Read-only file system', (string) (error_get_last()['message'] ?? ''));
        self::assertFileDoesNotExist(self::APP . '/peak-probe-write.txt');
        self::assertFalse(@mkdir(self::APP . '/storage/peak-probe-dir'));
        self::assertFalse(@touch(self::APP . '/bootstrap/cache/peak-probe'));
    }

    public function testMailQueueAndNotificationAreRecordedNotSent(): void
    {
        $o = $this->probe();

        self::assertSame(1, $o['mail_recorded']);
        self::assertSame(1, $o['queue_recorded']);
        self::assertSame(1, $o['notification_recorded']);
        self::assertSame('Illuminate\\Support\\Testing\\Fakes\\MailFake', $o['report']['fakes']['mail']);
        self::assertSame('Illuminate\\Support\\Testing\\Fakes\\QueueFake', $o['report']['fakes']['queue']);
        self::assertSame('Illuminate\\Support\\Testing\\Fakes\\NotificationFake', $o['report']['fakes']['notification']);
    }

    public function testEventsAreNotReplaced(): void
    {
        $o = $this->probe();

        self::assertTrue($o['event_listener_ran']);
        self::assertSame('Illuminate\\Events\\Dispatcher', $o['event_dispatcher']);
    }

    public function testHttpFailsByDefaultAndOnlyTheHostIsRecorded(): void
    {
        $o = $this->probe();

        self::assertSame('Illuminate\\Http\\Client\\ConnectionException', $o['http']);
        self::assertSame(['api.example.test'], $o['external_hosts']);
        self::assertStringNotContainsString('SECRET', json_encode($o));
    }

    public function testCallsThatBypassTheHttpClientFailOnTheNetworkAndAreClassified(): void
    {
        $o = $this->probe();

        self::assertSame('external_call_blocked', $o['guzzle'][1], json_encode($o['guzzle']));
        self::assertSame(['failed', 'external_call_blocked'], $o['file_get_contents']);
        self::assertLessThan(8.0, $o['guzzle_seconds'], 'a blocked call must fail fast');
        self::assertLessThan(8.0, $o['file_get_contents_seconds']);
    }

    public function testCacheIsTheArrayStoreAndStartsEmpty(): void
    {
        $o = $this->probe();

        self::assertSame('Illuminate\\Cache\\ArrayStore', $o['report']['fakes']['cache_store']);
        self::assertSame('array', $o['report']['fakes']['session_driver']);
        self::assertSame(['v', null], $o['cache']);
    }

    public function testStorageStaysInTheScratchDirectoryAndAnOutsideDiskIsReplaced(): void
    {
        $o = $this->probe();

        self::assertTrue($o['storage_local']);
        self::assertTrue($o['storage_s3_replaced']);
        self::assertContains('s3', $o['report']['fakes']['disks_replaced']);
        self::assertStringStartsWith($o['scratch'] . '/', $o['storage_path']);
    }

    public function testEveryConnectionAfterStartIsTheMeasurementDatabase(): void
    {
        $o = $this->probe();
        $name = self::config()->dbNameForScale(self::SCALE);

        self::assertNotSame([], $o['connections']);
        foreach ($o['connections'] as $connection => $database) {
            self::assertSame($name, $database, $connection);
        }
        self::assertSame($name, $o['select_database']);
        self::assertSame($name, $o['report']['connections']['verified_database']);
        self::assertStringStartsWith('peak_sim_measure_', $name);
    }

    public function testAHardCodedHostDatabaseUserAndDriverAreReplaced(): void
    {
        $probe = ProbeApp::create(self::APP, 'hard');
        try {
            $probe->hardcodeDatabaseConfig();
            $o = $this->probe($probe->root);
        } finally {
            $probe->remove();
        }

        $name = self::config()->dbNameForScale(self::SCALE);
        $connections = $o['report']['connections'];
        self::assertContains('mysql', $connections['overridden'], 'the hard-coded mysql connection was rewritten');
        self::assertContains('mariadb', $connections['overridden']);
        self::assertArrayHasKey('pgsql', $connections['replaced_non_mysql']);
        self::assertSame('pgsql', $connections['default'], 'the app\'s default was a pgsql connection');
        foreach ($o['connections'] as $connection => $database) {
            self::assertSame($name, $database, $connection);
        }
        self::assertSame($name, $o['select_database'], 'even a default of pgsql ends up on the measurement database');
    }

    public function testStartingAgainstTheWrongHostIsRefused(): void
    {
        [$exit, $attempts, $err] = self::measure(['--scale=' . self::SCALE, '--uri=/ok/eager'], ['PEAK_MEASURE_DB_HOST' => 'db.example.com']);

        self::assertSame(10, $exit);
        self::assertSame([], $attempts);
        self::assertStringContainsString('refused', $err);
    }

    public function testNoEnvironmentIsRefused(): void
    {
        [$exit, , $err] = self::php(self::RUNTIME . '/bin/measure-one', ['--scale=100', '--uri=/ok/eager'], ['PEAK_MEASURE_DB_HOST' => '', 'PEAK_MEASURE_DB_PASSWORD' => '']);

        self::assertSame(10, $exit);
        self::assertStringContainsString('refused', $err);
    }

    public function testAnAppWithoutVendorIsNotStarted(): void
    {
        [$exit, , $err] = self::measure(['--scale=' . self::SCALE, '--uri=/ok/eager'], ['PEAK_MEASURE_APP_ROOT' => '/tmp']);

        self::assertSame(12, $exit);
        self::assertStringContainsString('vendor_missing', $err);
    }

    public function testBadArgumentsGiveUsageAndExit2(): void
    {
        [$exit, , $err] = self::php(self::RUNTIME . '/bin/measure-one', ['--scale=100']);

        self::assertSame(2, $exit);
        self::assertStringContainsString('usage:', $err);
    }

    /** @return array<string, mixed> */
    private function probe(?string $appRoot = null): array
    {
        [$exit, $out, $err] = self::php(
            __DIR__ . '/Support/fakes.php',
            [(string) self::SCALE],
            $appRoot === null ? [] : ['PEAK_MEASURE_APP_ROOT' => $appRoot],
        );
        self::assertSame(0, $exit, $err . $out);

        return json_decode(trim($out), true, 512, JSON_THROW_ON_ERROR);
    }
}
