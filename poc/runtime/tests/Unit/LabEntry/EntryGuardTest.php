<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\LabEntry;

use PeakSim\Runtime\LabEntry\EntryGuard;
use PeakSim\Runtime\LabEntry\LabWriteRefused;
use PeakSim\Runtime\Run\QueryCounter;
use PeakSim\Runtime\Tests\Unit\Boot\Stubs\FakeEvents;
use PHPUnit\Framework\TestCase;

final class EntryGuardTest extends TestCase
{
    private const DB = 'peak_sim_measure_abc123_lab_10000';

    private string $dir;

    private string $latch;

    private string|false $errorLog = false;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/entry-guard-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->latch = $this->dir . '/contaminated.flag';
        // the guard says what it latched through error_log(); keep that out of the test output
        $this->errorLog = ini_set('error_log', $this->dir . '/php.log');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->errorLog === false ? '' : $this->errorLog);
        @unlink($this->latch);
        @unlink($this->dir . '/php.log');
        @rmdir($this->dir);
    }

    public function testOnlyGetAndHeadAreServed(): void
    {
        self::assertNull(EntryGuard::methodRefusal('GET'));
        self::assertNull(EntryGuard::methodRefusal('HEAD'));
        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'get', ''] as $method) {
            self::assertSame('only GET and HEAD are served', EntryGuard::methodRefusal($method), $method);
        }
    }

    public function testALatchFileIsReadBack(): void
    {
        self::assertNull(EntryGuard::latchRefusal($this->latch));
        file_put_contents($this->latch, "/books/x write_outside_views: update entities\n");
        self::assertSame('/books/x write_outside_views: update entities', EntryGuard::latchRefusal($this->latch));
    }

    public function testAViewsWriteIsSentAndLeavesNothing(): void
    {
        [$guard, $db, $events] = $this->watching();
        $connection = $db->connections['mysql'];

        $connection->run('select * from `entities` where `slug` = ?');
        $connection->run('update `views` set `views` = ?, `updated_at` = ? where `id` = ?');
        $connection->run('insert into `views` (`user_id`, `views`) values (?, ?)');

        self::assertCount(3, $connection->sent);
        self::assertNull($guard->finish($db, new QueryCounter(self::DB, 'mysql')));
        self::assertFileDoesNotExist($this->latch);
    }

    public function testAWriteOutsideViewsIsNotSentAndLeavesTheLatch(): void
    {
        [$guard, $db] = $this->watching('/books/a-book');
        $connection = $db->connections['mysql'];

        try {
            $connection->run('update `entities` set `name` = ? where `id` = ?');
            self::fail('the statement was sent');
        } catch (LabWriteRefused $e) {
            self::assertStringContainsString('write_outside_views', $e->getMessage());
        }

        self::assertSame([], $connection->sent, 'a statement refused before it was sent must not reach the database');
        self::assertFileExists($this->latch);
        self::assertStringStartsWith('/books/a-book write_outside_views: update `entities`', (string) file_get_contents($this->latch));
        self::assertStringContainsString('write_outside_views', (string) $guard->finish($db, new QueryCounter(self::DB, 'mysql')));
        self::assertNotNull(EntryGuard::latchRefusal($this->latch));
    }

    public function testAnImplicitCommitIsNotSent(): void
    {
        foreach (['commit', 'start transaction', 'set autocommit = 0', 'alter table `views` add x int'] as $sql) {
            @unlink($this->latch);
            [, $db] = $this->watching();
            try {
                $db->connections['mysql']->run($sql);
                self::fail('sent: ' . $sql);
            } catch (LabWriteRefused) {
                self::assertFileExists($this->latch, $sql);
            }
            self::assertSame([], $db->connections['mysql']->sent, $sql);
        }
    }

    public function testAWriteOnAnotherConnectionIsNotSent(): void
    {
        [, $db] = $this->watching('/x', ['mysql', 'audit']);
        $this->expectException(LabWriteRefused::class);
        try {
            $db->connections['audit']->run('update `views` set `views` = 1 where id = 1');
        } finally {
            self::assertSame([], $db->connections['audit']->sent);
            self::assertFileExists($this->latch);
        }
    }

    public function testAConnectionMadeLaterCarriesTheWatchToo(): void
    {
        [$guard, $db, $events] = $this->watching();
        $later = new FakeConnection('replica');
        $db->connections['replica'] = $later;
        $events->fire('Illuminate\\Database\\Events\\ConnectionEstablished', (object) ['connection' => $later]);
        // the same connection announced again (a reconnect) does not get a second hook
        $events->fire('Illuminate\\Database\\Events\\ConnectionEstablished', (object) ['connection' => $later]);

        self::assertCount(1, $later->hooks);
        $this->expectException(LabWriteRefused::class);
        $later->run('delete from `entities`');
    }

    public function testAConnectionWithoutAHookIsLookedAtAfterTheStatementRan(): void
    {
        [$guard, $db, $events] = $this->watching();
        $stranger = new FakeConnection('mysql'); // the default connection, but not one the guard was given

        $events->fire('Illuminate\\Database\\Events\\QueryExecuted', (object) ['connection' => $stranger, 'connectionName' => 'mysql', 'sql' => 'select 1']);
        self::assertFileDoesNotExist($this->latch);

        $events->fire('Illuminate\\Database\\Events\\QueryExecuted', (object) ['connection' => $stranger, 'connectionName' => 'mysql', 'sql' => 'update `entities` set `name` = 1']);
        self::assertFileExists($this->latch);
        self::assertStringContainsString('write_outside_views', (string) $guard->finish($db, new QueryCounter(self::DB, 'mysql')));
    }

    public function testTheFirstReasonStaysInTheLatch(): void
    {
        [, $db] = $this->watching('/first');
        $connection = $db->connections['mysql'];
        foreach (['update `entities` set a = 1', 'delete from `sessions`'] as $sql) {
            try {
                $connection->run($sql);
            } catch (LabWriteRefused) {
            }
        }
        self::assertStringContainsString('update `entities`', (string) file_get_contents($this->latch));
        self::assertStringNotContainsString('sessions', (string) file_get_contents($this->latch));
    }

    public function testAStatementThatNamedAnotherSchemaLeavesTheLatchAfterTheRequest(): void
    {
        [$guard, $db] = $this->watching();
        $counter = new QueryCounter(self::DB, 'mysql');
        $counter->start();
        $counter->record('mysql', 'select * from `other_db`.`entities`');
        $counter->stop();

        $problem = $guard->finish($db, $counter);

        self::assertSame('a statement referred to a schema other than the measurement database: other_db', $problem);
        self::assertFileExists($this->latch);
    }

    public function testWhatTheCounterSawAsNotRollbackableLeavesTheLatch(): void
    {
        [$guard, $db] = $this->watching();
        $counter = new QueryCounter(self::DB, 'mysql');
        $counter->start();
        $counter->record('mysql', 'commit');
        $counter->stop();

        self::assertStringStartsWith('transaction_control:', (string) $guard->finish($db, $counter));
        self::assertFileExists($this->latch);
    }

    public function testATransactionLeftOpenLeavesTheLatchAndIsRolledBack(): void
    {
        [$guard, $db] = $this->watching();
        $db->level = 2;

        $problem = $guard->finish($db, new QueryCounter(self::DB, 'mysql'));

        self::assertStringStartsWith('transaction_left_open:', (string) $problem);
        self::assertSame(0, $db->level);
        self::assertSame(2, $db->rolledBack);
        self::assertFileExists($this->latch);
    }

    public function testNoTransactionIsOpenedByTheGuard(): void
    {
        [$guard, $db] = $this->watching();
        $db->connections['mysql']->run('update `views` set `views` = 1 where id = 1');
        $guard->finish($db, new QueryCounter(self::DB, 'mysql'));

        self::assertSame(0, $db->began, 'the lab entry does not wrap a request in a transaction');
        self::assertSame(0, $db->rolledBack);
    }

    /**
     * @param list<string> $names
     *
     * @return array{EntryGuard, FakeDb, FakeEvents}
     */
    private function watching(string $uri = '/books/x', array $names = ['mysql']): array
    {
        $guard = new EntryGuard($this->latch, self::DB, 'mysql', $uri);
        $db = new FakeDb();
        foreach ($names as $name) {
            $db->connections[$name] = new FakeConnection($name);
        }
        $events = new FakeEvents();
        $guard->watch($db, $events);

        return [$guard, $db, $events];
    }
}

/** What Laravel's connection does around a statement: the before-execute hooks run, then it is sent. */
final class FakeConnection
{
    /** @var list<\Closure> */
    public array $hooks = [];

    /** @var list<string> */
    public array $sent = [];

    public function __construct(private readonly string $name)
    {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function beforeExecuting(\Closure $callback): static
    {
        $this->hooks[] = $callback;

        return $this;
    }

    public function run(string $sql): void
    {
        foreach ($this->hooks as $hook) {
            $hook($sql, [], $this);
        }
        $this->sent[] = $sql;
    }
}

final class FakeDb
{
    /** @var array<string, FakeConnection> */
    public array $connections = [];

    public int $level = 0;

    public int $began = 0;

    public int $rolledBack = 0;

    /** @return array<string, FakeConnection> */
    public function getConnections(): array
    {
        return $this->connections;
    }

    public function transactionLevel(): int
    {
        return $this->level;
    }

    public function beginTransaction(): void
    {
        $this->began++;
        $this->level++;
    }

    public function rollBack(): void
    {
        $this->rolledBack++;
        $this->level--;
    }
}
