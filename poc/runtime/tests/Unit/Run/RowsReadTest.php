<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Run;

use PDO;
use PeakSim\Runtime\Run\Attempt;
use PeakSim\Runtime\Run\RowsRead;
use PHPUnit\Framework\TestCase;

/**
 * How many rows each table gave to the app during one request: the rows the statements returned, counted by the table
 * names in the statement. Not the rows the database looked at, and not the size of the tables.
 */
final class RowsReadTest extends TestCase
{
    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('create table orders (id integer primary key, customer_id integer)');
        $pdo->exec('create table customers (id integer primary key, name text)');
        for ($i = 1; $i <= 6; $i++) {
            $pdo->exec("insert into orders (customer_id) values (" . (($i % 2) + 1) . ')');
        }
        $pdo->exec("insert into customers (name) values ('a'), ('b')");

        return $pdo;
    }

    public function testRowsReturnedByASelectAreCountedForItsTable(): void
    {
        $pdo = $this->pdo();
        $reads = RowsRead::install($pdo);
        $reads->start();

        $pdo->query('select * from orders')->fetchAll();
        $reads->stop();

        $this->assertSame(['orders' => 6], $reads->rows());
    }

    public function testEveryTableNamedInAJoinIsCreditedWithTheRowsOfTheStatement(): void
    {
        $pdo = $this->pdo();
        $reads = RowsRead::install($pdo);
        $reads->start();

        $pdo->query('select o.id from orders o join customers c on c.id = o.customer_id')->fetchAll();
        $reads->stop();

        $this->assertSame(['customers' => 6, 'orders' => 6], $reads->rows());
    }

    public function testRowsFetchedOneByOneAndASingleColumnAreCounted(): void
    {
        $pdo = $this->pdo();
        $reads = RowsRead::install($pdo);
        $reads->start();

        $statement = $pdo->query('select * from orders where id <= 3');
        while ($statement->fetch(PDO::FETCH_ASSOC) !== false) {
        }
        $pdo->query('select name from customers')->fetchColumn();
        $reads->stop();

        $this->assertSame(['customers' => 1, 'orders' => 3], $reads->rows());
    }

    public function testNothingIsCountedWhileItIsNotStarted(): void
    {
        $pdo = $this->pdo();
        $reads = RowsRead::install($pdo);

        $pdo->query('select * from orders')->fetchAll();
        $reads->start();
        $reads->stop();
        $pdo->query('select * from orders')->fetchAll();

        $this->assertSame([], $reads->rows());
    }

    public function testStartingAgainBeginsFromNothing(): void
    {
        $pdo = $this->pdo();
        $reads = RowsRead::install($pdo);
        $reads->start();
        $pdo->query('select * from orders')->fetchAll();
        $reads->stop();
        $reads->start();
        $pdo->query('select * from customers')->fetchAll();
        $reads->stop();

        $this->assertSame(['customers' => 2], $reads->rows());
    }

    public function testTheRowsAReadingGivesAreTheSameAsWithoutTheCounting(): void
    {
        $plain = $this->pdo()->query('select * from orders order by id')->fetchAll(PDO::FETCH_ASSOC);
        $pdo = $this->pdo();
        RowsRead::install($pdo);

        $this->assertSame($plain, $pdo->query('select * from orders order by id')->fetchAll(PDO::FETCH_ASSOC));
        $statement = $pdo->prepare('select name from customers where id = ?');
        $statement->execute([2]);
        $this->assertSame('b', $statement->fetchColumn());
    }

    public function testAConnectionThatCannotBeCountedGivesNoFigureAtAll(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_PERSISTENT => false]);
        $reads = RowsRead::install($pdo);
        $this->assertTrue($reads->available());

        $this->assertNull(RowsRead::unavailable()->rows());
        $this->assertFalse(RowsRead::unavailable()->available());
    }

    public function testTheCountsTravelInTheAttemptRecordOnlyWhenThereAreSome(): void
    {
        $with = new Attempt('r', 'GET', 10, 'N', Attempt::COLD, 200, 1, [], ['orders'], 10, 1, false, 'ok', null, rowsRead: ['orders' => 4]);
        $without = new Attempt('r', 'GET', 10, 'N', Attempt::COLD, 200, 1, [], ['orders'], 10, 1, false, 'ok', null);

        $this->assertSame(['orders' => 4], $with->toArray()['rows_read']);
        $this->assertArrayNotHasKey('rows_read', $without->toArray());
        $this->assertSame(['orders' => 4], Attempt::fromArray(json_decode($with->toJson(), true, 512, JSON_THROW_ON_ERROR))->rowsRead);
        $this->assertNull(Attempt::fromArray($without->toArray())->rowsRead);
    }
}
