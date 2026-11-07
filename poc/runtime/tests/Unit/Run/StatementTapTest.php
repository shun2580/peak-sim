<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Run;

use PDO;
use PDOException;
use PDOStatement;
use PeakSim\Runtime\Run\RowsExamined;
use PeakSim\Runtime\Run\StatementTap;
use PHPUnit\Framework\TestCase;

/**
 * The tap on the statements of one request. The counters are played by a PDO whose `SHOW SESSION STATUS` answers from a
 * table the test moves by hand and which charges a fixed cost for every SHOW it answers, as MySQL does; the plans by
 * SQLite (its EXPLAIN has other columns, so what is checked there is that the plumbing runs and an error is kept, not
 * the plan itself).
 */
final class StatementTapTest extends TestCase
{
    private function tap(TapStatusPdo $pdo): StatementTap
    {
        $examined = RowsExamined::install($pdo);

        return new StatementTap($pdo, $examined->baseline(), 'mysql');
    }

    public function testEachStatementIsGivenTheRowsReadSinceTheOneBefore(): void
    {
        $pdo = new TapStatusPdo(4);
        $tap = $this->tap($pdo);

        $tap->start();
        $pdo->counters['Handler_read_key'] += 5;
        $tap->onQuery('select * from `a` where `id` = ?', [1]);
        $pdo->counters['Handler_read_next'] += 7;
        $pdo->counters['Handler_read_first'] += 1;
        $tap->onQuery('select * from `a` where `id` = ?', [2]);
        $tap->onQuery('update `a` set `b` = ?', ['x']);
        $tap->stop();

        $s = $tap->ledger()->statements();
        self::assertSame(['select * from `a` where `id` = ?', 'update `a` set `b` = ?'], array_column($s, 'sql'));
        self::assertSame(5 + 8, $s[0]['rows_sum']);
        self::assertSame(5, $s[0]['rows_min']);
        self::assertSame(8, $s[0]['rows_max']);
        self::assertSame(0, $s[1]['rows_sum'], 'the cost of the readings themselves is taken off');
        self::assertSame(3, $tap->ledger()->events());
    }

    public function testTheSevenCountersAreAddedAndNothingElse(): void
    {
        $pdo = new TapStatusPdo(0);
        $tap = $this->tap($pdo);

        $tap->start();
        foreach (RowsExamined::COUNTERS as $i => $name) {
            $pdo->counters[$name] += 10 ** $i;
        }
        $pdo->counters['Handler_read_rnd_deleted'] += 5000000;
        $tap->onQuery('select 1', []);

        self::assertSame(1111111, $tap->ledger()->rowsSum());
    }

    public function testNothingIsNotedBeforeStartOrAfterStop(): void
    {
        $pdo = new TapStatusPdo(0);
        $tap = $this->tap($pdo);
        $dispatcher = new TapDispatcher();
        $tap->listenTo($dispatcher);
        self::assertSame(['Illuminate\\Database\\Events\\QueryExecuted'], array_keys($dispatcher->listeners));

        $dispatcher->fire(new TapQueryEvent('select 0', [], 'mysql'));
        $tap->start();
        $pdo->counters['Handler_read_key'] += 2;
        $dispatcher->fire(new TapQueryEvent('select 1', [9], 'mysql'));
        $tap->stop();
        $dispatcher->fire(new TapQueryEvent('select 2', [], 'mysql'));

        self::assertSame(1, $tap->ledger()->events());
        self::assertSame('select 1', $tap->ledger()->statements()[0]['sql']);
        self::assertSame(2, $tap->ledger()->statements()[0]['rows_sum']);
    }

    public function testAStatementOfAnotherConnectionIsCountedButItsRowsAreNotClaimed(): void
    {
        $pdo = new TapStatusPdo(0);
        $tap = $this->tap($pdo);

        $tap->start();
        $pdo->counters['Handler_read_key'] += 3;
        $tap->onQuery('select * from `other`', [], 'archive');
        $tap->onQuery('select * from `mine`', [], 'mysql');
        $tap->stop();

        $s = $tap->ledger()->statements();
        self::assertSame(1, $s[0]['unread']);
        self::assertNull($s[0]['rows_min']);
        self::assertSame(3, $s[1]['rows_sum'], "this session's reads go to the statement of this session");
    }

    public function testWhenTheCountersCannotBeReadTheStatementsAreStillCounted(): void
    {
        $pdo = new TapStatusPdo(0);
        $tap = new StatementTap($pdo, null, 'mysql'); // as when RowsExamined is unavailable

        self::assertFalse($tap->countersAvailable());
        $tap->start();
        $tap->onQuery('select 1', []);
        $tap->onQuery('select 1', []);
        $tap->stop();

        self::assertSame(2, $tap->ledger()->events());
        self::assertSame(2, $tap->ledger()->unread());
        self::assertSame(0, $pdo->shows, 'the tap reads nothing when the counters cannot be used');
    }

    public function testAReadingThatFailsMakesThatStatementUnreadAndTheNextOneReadable(): void
    {
        $pdo = new TapStatusPdo(0);
        $tap = $this->tap($pdo);
        $tap->start();

        $pdo->denied = true;
        $tap->onQuery('select 1', []);
        $pdo->denied = false;
        $tap->onQuery('select 2', []); // there is no reading before it to take a difference from
        $pdo->counters['Handler_read_key'] += 6;
        $tap->onQuery('select 3', []);

        $s = $tap->ledger()->statements();
        self::assertSame(1, $s[0]['unread']);
        self::assertSame(1, $s[1]['unread']);
        self::assertSame(6, $s[2]['rows_sum']);
        self::assertSame(0, $s[2]['unread']);
    }

    public function testWithNoCostPerReadingTheStatementsAddUpToTheFigureOfTheWindow(): void
    {
        $pdo = new TapStatusPdo(0);
        $examined = RowsExamined::install($pdo);
        $tap = new StatementTap($pdo, $examined->baseline(), 'mysql');

        $examined->start();
        $tap->start();
        $pdo->counters['Handler_read_key'] += 5;
        $tap->onQuery('select 1', []);
        $pdo->counters['Handler_read_rnd_next'] += 7;
        $tap->onQuery('select 2', []);
        $tap->stop();
        $examined->stop();

        $doc = $tap->document([], $examined->rows(), []);
        self::assertSame(12, $examined->rows());
        self::assertSame(12, $doc['window']['rows_sum']);
        self::assertSame(0, $doc['window']['unaccounted']);
    }

    public function testWhenAReadingCostsSomethingTheLeftOverShowsThatTheTapDisturbedTheFigure(): void
    {
        $pdo = new TapStatusPdo(4);
        $examined = RowsExamined::install($pdo);
        $tap = new StatementTap($pdo, $examined->baseline(), 'mysql');

        $examined->start();
        $tap->start();
        $pdo->counters['Handler_read_key'] += 5;
        $tap->onQuery('select 1', []);
        $pdo->counters['Handler_read_rnd_next'] += 7;
        $tap->onQuery('select 2', []);
        $tap->stop();
        $examined->stop();

        $doc = $tap->document([], $examined->rows(), []);
        self::assertSame(12, $doc['window']['rows_sum'], 'what each statement read is still right');
        // The tap's readings (one at the start and one per statement) are inside the window: 3 x 4 rows the figure counts.
        self::assertSame(3 * 4, $doc['window']['unaccounted']);
    }

    public function testThePlansOfEveryExplainableTextAreAskedForWithTheFirstAndTheLastBindings(): void
    {
        $pdo = new TapStatusPdo(0);
        $pdo->exec('create table a (id integer primary key, b text)');
        $tap = $this->tap($pdo);
        $tap->start();
        $tap->onQuery('select * from a where id = ?', [1]);
        $tap->onQuery('select * from a where id = ?', [2]);
        $tap->onQuery('update a set b = ? where id = ?', ['x', 1]);
        $tap->onQuery('set names utf8mb4', []);
        $tap->onQuery('select * from nowhere where id = ?', [1]);
        $tap->stop();

        $explains = $tap->explainAll();

        self::assertSame(['select * from a where id = ?', 'update a set b = ? where id = ?', 'select * from nowhere where id = ?'], array_keys($explains), 'a set is not explained');
        self::assertTrue($explains['select * from a where id = ?']['first']['ok']);
        self::assertTrue($explains['select * from a where id = ?']['last']['ok']);
        self::assertTrue($explains['update a set b = ? where id = ?']['first']['ok']);
        self::assertFalse($explains['select * from nowhere where id = ?']['first']['ok'], 'a plan that cannot be taken is kept as an error');
        self::assertStringContainsString('nowhere', $explains['select * from nowhere where id = ?']['first']['error']);
        self::assertSame($explains['select * from nowhere where id = ?']['first'], $explains['select * from nowhere where id = ?']['last'], 'one run: one plan, shared');
    }

    public function testAnErrorOfAPlanIsCutShort(): void
    {
        $pdo = new TapStatusPdo(0);
        $tap = $this->tap($pdo);
        $tap->start();
        $tap->onQuery('select * from ' . str_repeat('n', 400), []);
        $tap->stop();

        $error = $tap->explainAll()['select * from ' . str_repeat('n', 400)]['first']['error'];
        self::assertLessThanOrEqual(160, mb_strlen($error));
    }

    public function testExplainRowsKeepEveryColumnInOrderInLowerCaseWithNumbersAsNumbers(): void
    {
        $rows = StatementTap::normalizeExplain([
            ['id' => '1', 'select_type' => 'SIMPLE', 'table' => 'pages', 'partitions' => null, 'type' => 'ref', 'possible_keys' => 'a,b', 'key' => 'a', 'key_len' => '8', 'ref' => 'const', 'rows' => '120', 'filtered' => '100.00', 'Extra' => 'Using where'],
            ['id' => 2, 'select_type' => 'DELETE', 'table' => 'pages', 'type' => 'range', 'key' => null, 'rows' => null, 'filtered' => null, 'Extra' => null, 'unknown_column' => 'dropped'],
        ]);

        self::assertSame(['id', 'select_type', 'table', 'partitions', 'type', 'possible_keys', 'key', 'key_len', 'ref', 'rows', 'filtered', 'extra'], array_keys($rows[0]));
        self::assertSame(1, $rows[0]['id']);
        self::assertSame(120, $rows[0]['rows']);
        self::assertSame(100.0, $rows[0]['filtered']);
        self::assertSame('8', $rows[0]['key_len'], 'key_len stays text');
        self::assertSame('Using where', $rows[0]['extra']);
        self::assertSame(2, $rows[1]['id']);
        self::assertNull($rows[1]['rows'], 'a null is not turned into 0');
        self::assertNull($rows[1]['partitions'], 'a column the server did not give is null');
        self::assertArrayNotHasKey('unknown_column', $rows[1]);
        self::assertSame([], StatementTap::normalizeExplain([]));
    }

    public function testTheFileIsWrittenAsOneJsonDocument(): void
    {
        $pdo = new TapStatusPdo(0);
        $tap = $this->tap($pdo);
        $tap->start();
        $pdo->counters['Handler_read_key'] += 2;
        $tap->onQuery('select ?', ["bad \xB1 utf8", str_repeat('y', 300)]);
        $tap->stop();

        $path = tempnam(sys_get_temp_dir(), 'tap');
        self::assertIsString($path);
        try {
            self::assertNull(StatementTap::write($path, $tap->document(['route' => 'r'], 2, [])));
            $doc = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            @unlink($path);
        }

        self::assertSame(1, $doc['tap_version']);
        self::assertSame('r', $doc['route']);
        self::assertSame(0, $doc['window']['unaccounted']);
        self::assertSame(2, $doc['statements'][0]['rows_sum']);
        self::assertStringEndsWith('…(+100)', $doc['statements'][0]['first_bindings'][1]);
    }

    public function testAFileThatCannotBeWrittenIsReportedNotThrown(): void
    {
        $message = StatementTap::write(sys_get_temp_dir() . '/no-such-directory-for-the-tap/x.json', ['a' => 1]);

        self::assertIsString($message);
    }
}

/** A PDO that answers `SHOW SESSION STATUS LIKE 'Handler_read%'` from a table the test moves by hand. */
final class TapStatusPdo extends PDO
{
    /** @var array<string, int> */
    public array $counters;

    public bool $denied = false;

    public int $shows = 0;

    public function __construct(public int $selfCost)
    {
        parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->counters = array_fill_keys(RowsExamined::COUNTERS, 0) + ['Handler_read_rnd_deleted' => 0];
    }

    #[\ReturnTypeWillChange]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if ($query !== "SHOW SESSION STATUS LIKE 'Handler_read%'") {
            return parent::query($query);
        }
        if ($this->denied) {
            throw new PDOException('SHOW denied');
        }
        $this->shows++;
        $selects = [];
        foreach ($this->counters as $name => $value) {
            $selects[] = sprintf("select '%s' as n, %d as v", $name, $value);
        }
        $statement = parent::query(implode(' union all ', $selects));
        // The SHOW read something itself; the next reading sees it.
        $this->counters['Handler_read_rnd_next'] += $this->selfCost;

        return $statement;
    }
}

/** The event dispatcher of the app, as far as the tap uses it. */
final class TapDispatcher
{
    /** @var array<string, list<callable>> */
    public array $listeners = [];

    public function listen(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function fire(object $event): void
    {
        foreach ($this->listeners['Illuminate\\Database\\Events\\QueryExecuted'] ?? [] as $listener) {
            $listener($event);
        }
    }
}

/** What Laravel's QueryExecuted carries, as far as the tap reads it. */
final class TapQueryEvent
{
    /** @param array<int, mixed> $bindings */
    public function __construct(public string $sql, public array $bindings, public string $connectionName, public ?object $connection = null)
    {
    }
}
