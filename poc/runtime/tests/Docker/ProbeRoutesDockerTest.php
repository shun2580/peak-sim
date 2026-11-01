<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Docker;

use PeakSim\Runtime\Tests\Docker\Support\DockerCase;
use PeakSim\Runtime\Tests\Docker\Support\ProbeApp;

/**
 * The paths the fixture app has no route for, driven through a scratch copy with a few extra routes: what is
 * measured, what is recorded as a reason, and what stops the measurement because the database cannot be trusted.
 */
final class ProbeRoutesDockerTest extends DockerCase
{
    private const SCALE = 1000;
    private const DIRTY = 10000;

    private static ProbeApp $probe;

    public static function setUpBeforeClass(): void
    {
        self::$probe = ProbeApp::create('/app', 'routes');
        self::prepare(self::SCALE, [20, 20, 20, 3]);
    }

    public static function tearDownAfterClass(): void
    {
        self::$probe->remove();
        self::drop(self::SCALE);
    }

    protected function tearDown(): void
    {
        self::drop(self::DIRTY);
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $env
     *
     * @return array{int, list<array<string, mixed>>, string}
     */
    private function run1(array $args, int $scale = self::SCALE, array $env = []): array
    {
        return self::measure(['--scale=' . $scale, ...$args], ['PEAK_MEASURE_APP_ROOT' => self::$probe->root] + $env);
    }

    public function testASwallowedOutsideCallIsStillRecordedAndNeverLeaksTheUrl(): void
    {
        [$exit, $attempts, $err] = $this->run1(['--uri=/probe/external', '--phases=warmup']);

        self::assertSame(0, $exit, $err);
        self::assertSame(200, $attempts[0]['status'], 'the app answered 200 after swallowing the failure');
        self::assertSame('external_call_blocked', $attempts[0]['class']);
        self::assertSame('hosts: api.example.test', $attempts[0]['detail']);
        self::assertStringNotContainsString('SECRET', json_encode($attempts) . $err);
        self::assertStringNotContainsString('secret-path', json_encode($attempts) . $err);
        self::assertCount(1, $attempts, 'a failed warm-up stops the measurement');
    }

    public function testAFailedWarmupStopsTheRemainingRequests(): void
    {
        [$exit, $attempts] = $this->run1(['--uri=/probe/external']);

        self::assertSame(0, $exit);
        self::assertCount(1, $attempts);
        self::assertSame(1, $attempts[0]['attempt']);
    }

    public function testAnExceptionIsRecordedWithItsClassAndMessage(): void
    {
        [, $attempts] = $this->run1(['--uri=/probe/boom', '--phases=warmup']);

        self::assertSame(500, $attempts[0]['status']);
        self::assertSame('exception', $attempts[0]['class']);
        self::assertStringStartsWith('RuntimeException: boom', $attempts[0]['detail']);
    }

    public function testAWriteIntoTheReadOnlyAppIsRecordedAsAnAppWriteAttempt(): void
    {
        [, $attempts] = $this->run1(['--uri=/probe/write-app', '--phases=warmup']);

        self::assertSame('app_write_attempt', $attempts[0]['class'], json_encode($attempts));
        self::assertStringContainsString('Read-only file system', $attempts[0]['detail']);
        self::assertFileDoesNotExist('/app/probe-write.txt');
    }

    public function testAForbiddenRouteIsRecordedAndTheGateBypassIsAnotherValue(): void
    {
        [, $plain] = $this->run1(['--uri=/probe/gate', '--phases=warmup']);
        self::assertSame(403, $plain[0]['status']);
        self::assertSame('forbidden', $plain[0]['class']);
        self::assertFalse($plain[0]['gate_bypass']);

        [, $bypass] = $this->run1(['--uri=/probe/gate', '--gate-bypass']);
        self::assertCount(3, $bypass);
        self::assertSame('ok', $bypass[1]['class']);
        self::assertTrue($bypass[1]['gate_bypass']);
    }

    public function testAnUnauthenticatedRouteBecomesMeasurableWhenAUserIsActingAs(): void
    {
        [, $anonymous] = $this->run1(['--uri=/probe/auth-only', '--phases=warmup']);
        self::assertSame(401, $anonymous[0]['status']);
        self::assertSame('unauthenticated', $anonymous[0]['class']);

        [$exit, $user, $err] = $this->run1(['--uri=/probe/auth-only', '--act-as=PeakProbe\\ProbeUser:1:web']);
        self::assertSame(0, $exit, $err);
        self::assertCount(3, $user);
        self::assertSame('ok', $user[1]['class']);
    }

    public function testActingAsAUserThatDoesNotExistIsAnUnfilledParameter(): void
    {
        [$exit, $attempts] = $this->run1(['--uri=/probe/auth-only', '--act-as=PeakProbe\\ProbeUser:99999:web']);

        self::assertSame(0, $exit);
        self::assertSame('unfilled_param', $attempts[0]['class']);
        self::assertNull($attempts[0]['queries']);
    }

    public function testASlowRequestIsStoppedAndRecordedAsATimeout(): void
    {
        $started = microtime(true);
        [$exit, $attempts, $err] = $this->run1(['--uri=/probe/sleep', '--phases=warmup'], self::SCALE, ['PEAK_MEASURE_REQUEST_TIMEOUT' => '2']);

        self::assertSame(0, $exit, $err);
        self::assertSame('timeout', $attempts[0]['class'], json_encode($attempts));
        self::assertLessThan(15.0, microtime(true) - $started);
    }

    public function testAWriteRouteWithoutTheTokenIsPageExpiredAndWithItIsMeasuredAndRolledBack(): void
    {
        [, $without] = $this->run1(['--uri=/ok/bulk', '--method=POST', '--phases=warmup']);
        self::assertSame(419, $without[0]['status']);
        self::assertSame('csrf_419', $without[0]['class']);

        $orders = fn (): int => (int) $this->pdo(self::SCALE)->query('select count(*) from orders')->fetchColumn();
        $before = $orders();
        [$exit, $with, $err] = $this->run1(['--uri=/ok/bulk', '--method=POST', '--csrf']);
        self::assertSame(0, $exit, $err);
        self::assertCount(3, $with);
        self::assertSame('ok', $with[1]['class']);
        self::assertSame($before, $orders(), 'the three inserts of the three requests were rolled back');
    }

    public function testADdlStatementStopsTheMeasurementAndIsNotMeasured(): void
    {
        self::prepare(self::DIRTY, [5, 5, 5, 3], self::$probe->root);

        [$exit, $attempts, $err] = $this->run1(['--uri=/probe/ddl', '--method=POST', '--csrf'], self::DIRTY);

        self::assertSame(14, $exit, $err);
        self::assertCount(1, $attempts);
        self::assertSame('not_rollbackable', $attempts[0]['class']);
        self::assertStringStartsWith('ddl: create table probe_t', $attempts[0]['detail']);
    }

    public function testAWriteOnAnotherConnectionIsNotRollbackable(): void
    {
        self::prepare(self::DIRTY, [5, 5, 5, 3], self::$probe->root);

        [$exit, $attempts, $err] = $this->run1(['--uri=/probe/other-connection', '--method=POST', '--csrf'], self::DIRTY);

        self::assertSame(14, $exit, $err);
        self::assertSame('not_rollbackable', $attempts[0]['class']);
        self::assertSame('other_connection: mariadb', $attempts[0]['detail']);
    }

    public function testACommitInsideTheRequestIsNotRollbackable(): void
    {
        self::prepare(self::DIRTY, [5, 5, 5, 3], self::$probe->root);

        [$exit, $attempts, $err] = $this->run1(['--uri=/probe/commit', '--method=POST', '--csrf'], self::DIRTY);

        self::assertSame(14, $exit, $err);
        self::assertSame('not_rollbackable', $attempts[0]['class']);
        self::assertStringStartsWith('transaction_control', $attempts[0]['detail']);
    }

    private function pdo(int $scale): \PDO
    {
        $c = self::config();

        return new \PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s', $c->dbHost, $c->dbPort, $c->dbNameForScale($scale)),
            $c->dbUser,
            $c->dbPassword,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
    }
}
