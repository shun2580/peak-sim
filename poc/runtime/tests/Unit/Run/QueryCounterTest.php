<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Run;

use PeakSim\Runtime\Run\QueryCounter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueryCounterTest extends TestCase
{
    private const DB = 'peak_sim_measure_abc123_100';

    public function testCountsOnlyWhileActive(): void
    {
        $counter = new QueryCounter(self::DB, 'mysql');
        $counter->record('mysql', 'select * from `orders`');
        self::assertSame(0, $counter->total());

        $counter->start();
        $counter->record('mysql', 'select * from `orders`');
        $counter->record('mysql', 'select * from `customers` where `id` in (?, ?)');
        $counter->stop();
        $counter->record('mysql', 'select 1');

        self::assertSame(2, $counter->total());
    }

    public function testStartResetsTheCounts(): void
    {
        $counter = new QueryCounter(self::DB, 'mysql');
        $counter->start();
        $counter->record('mysql', 'select 1');
        $counter->start();
        self::assertSame(0, $counter->total());
        self::assertSame([], $counter->byConnection());
    }

    public function testCountsEveryConnectionSeparately(): void
    {
        $counter = new QueryCounter(self::DB, 'mysql');
        $counter->start();
        $counter->record('mysql', 'select * from a');
        $counter->record('mysql', 'select * from b');
        $counter->record('audit', 'select * from c');

        self::assertSame(3, $counter->total());
        self::assertSame(['mysql' => 2, 'audit' => 1], $counter->byConnection());
    }

    public function testCollectsTableNamesWithoutDuplicates(): void
    {
        $counter = new QueryCounter(self::DB, 'mysql');
        $counter->start();
        $counter->record('mysql', 'select * from `orders` inner join `customers` on `customers`.`id` = `orders`.`customer_id`');
        $counter->record('mysql', 'select * from `customers` where id = ?');
        $counter->record('mysql', 'insert into `items` (`a`) values (?)');

        self::assertSame(['customers', 'items', 'orders'], $counter->tables());
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function tableCases(): iterable
    {
        yield 'plain select' => ['select * from orders', ['orders']];
        yield 'backticks' => ['select * from `orders` where `id` = ?', ['orders']];
        yield 'join' => ['select * from a left join b on a.id = b.a_id join `c` on 1', ['a', 'b', 'c']];
        yield 'insert' => ['insert into `items` (`order_id`) values (?)', ['items']];
        yield 'update' => ['update `orders` set `status` = ? where `id` = ?', ['orders']];
        yield 'delete' => ['delete from `orders` where `id` = ?', ['orders']];
        yield 'subquery in from has no table name of its own' => ['select * from (select 1) as t', []];
        yield 'schema qualified keeps only the table here' => ['select * from `information_schema`.`tables` where 1', ['tables']];
        yield 'upper case' => ['SELECT * FROM Orders JOIN Items ON 1', ['Orders', 'Items']];
        yield 'column dot is not a table' => ['select orders.id from orders', ['orders']];
        yield 'none' => ['select 1', []];
    }

    /** @param list<string> $expected */
    #[DataProvider('tableCases')]
    public function testTableExtraction(string $sql, array $expected): void
    {
        self::assertSame($expected, QueryCounter::tablesIn($sql));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function implicitCommitCases(): iterable
    {
        foreach ([
            'alter table `orders` add column x int',
            'ALTER TABLE orders ADD x INT',
            '  create table t (id int)',
            'drop table if exists t',
            'truncate table orders',
            'rename table a to b',
            'lock tables a write',
            'unlock tables',
            'set autocommit = 0',
            'start transaction',
            'begin',
            'commit',
            'grant all on x.* to y',
            'flush privileges',
            'analyze table orders',
            'optimize table orders',
            'repair table orders',
            "load data infile '/x' into table t",
            'call do_something()',
        ] as $sql) {
            yield $sql => [$sql, true];
        }
        foreach ([
            'select * from orders',
            'insert into orders (id) values (?)',
            'update orders set a = 1',
            'delete from orders',
            'select * from `created_things`',
            'select * from altered',
            'set @a = 1',
            'savepoint trans2',
            'show tables',
        ] as $sql) {
            yield $sql => [$sql, false];
        }
    }

    #[DataProvider('implicitCommitCases')]
    public function testImplicitCommitDetection(string $sql, bool $expected): void
    {
        self::assertSame($expected, QueryCounter::isImplicitCommit($sql));
    }

    public function testImplicitCommitStatementsAreCollectedForTheClassifier(): void
    {
        $counter = new QueryCounter(self::DB, 'mysql');
        $counter->start();
        $counter->record('mysql', 'select 1');
        $counter->record('mysql', 'alter table `orders` add column `x` int');

        self::assertSame(['alter table `orders` add column `x` int'], $counter->implicitCommits());
        self::assertSame('ddl: alter table `orders` add column `x` int', $counter->rollbackProblem());
    }

    public function testRollbackProblemIsNullForPlainTraffic(): void
    {
        $counter = new QueryCounter(self::DB, 'mysql');
        $counter->start();
        $counter->record('mysql', 'insert into orders (id) values (?)');
        self::assertNull($counter->rollbackProblem());
    }

    public function testWriteOnAnotherConnectionCannotBeRolledBack(): void
    {
        $counter = new QueryCounter(self::DB, 'mysql');
        $counter->start();
        $counter->record('audit', 'select * from logs');
        self::assertNull($counter->rollbackProblem(), 'a read on another connection is harmless');

        $counter->record('audit', 'insert into logs (a) values (?)');
        self::assertSame('other_connection: audit', $counter->rollbackProblem());
    }

    public function testForeignSchemaIsDetectedButInformationSchemaIsAllowed(): void
    {
        $counter = new QueryCounter(self::DB, 'mysql');
        $counter->start();
        $counter->record('mysql', 'select * from `information_schema`.`tables` where `table_schema` = ?');
        $counter->record('mysql', 'select * from `' . self::DB . '`.`orders`');
        self::assertSame([], $counter->foreignSchemas());

        $counter->record('mysql', 'select * from `app`.`users`');
        $counter->record('mysql', 'use other');
        self::assertSame(['app', 'other'], $counter->foreignSchemas());
    }

    public function testStatementTextIsNotKeptBeyondWhatTheClassifierNeeds(): void
    {
        $counter = new QueryCounter(self::DB, 'mysql');
        $counter->start();
        $counter->record('mysql', 'drop table ' . str_repeat('x', 500));
        self::assertLessThanOrEqual(200 + strlen('ddl: '), strlen((string) $counter->rollbackProblem()));
    }
}
