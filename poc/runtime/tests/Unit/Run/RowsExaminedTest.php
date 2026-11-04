<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Run;

use PDO;
use PDOException;
use PDOStatement;
use PeakSim\Runtime\Run\RowsExamined;
use PeakSim\Runtime\Run\RowsRead;
use PHPUnit\Framework\TestCase;

/**
 * The rows the database looked at: the growth of the seven Handler_read_* counters of the app's session between two
 * snapshots, less the cost of taking a snapshot. The counters are played by a PDO whose `SHOW SESSION STATUS` answers
 * from a table the test moves by hand, and which charges a fixed cost for every SHOW it answers, as MySQL does.
 */
final class RowsExaminedTest extends TestCase
{
    private function pdo(int $selfCost = 4): FakeStatusPdo
    {
        return new FakeStatusPdo($selfCost);
    }

    public function testTheBaselineIsTheCostOfOnePairOfSnapshotsAndIsTakenOffTheResult(): void
    {
        $pdo = $this->pdo(4);
        $examined = RowsExamined::install($pdo);

        self::assertTrue($examined->available());
        self::assertSame(4, $examined->baseline());

        $examined->start();
        $pdo->counters['Handler_read_key'] += 30; // what the request did
        $examined->stop();

        self::assertSame(30, $examined->rows());
    }

    public function testTheSevenCountersAreAddedAndNothingElse(): void
    {
        $pdo = $this->pdo(0);
        $examined = RowsExamined::install($pdo);
        $examined->start();
        foreach (RowsExamined::COUNTERS as $i => $name) {
            $pdo->counters[$name] += 10 ** $i; // 1, 10, 100, ... 1000000: each counter shows in its own digit
        }
        $pdo->counters['Handler_read_rnd_deleted'] += 5000000; // a Handler_read% counter that is not one of the seven
        $examined->stop();

        self::assertSame(1111111, $examined->rows());
    }

    public function testARequestThatReadsNothingExaminedZeroRows(): void
    {
        $pdo = $this->pdo(7);
        $examined = RowsExamined::install($pdo);
        $examined->start();
        $examined->stop();

        self::assertSame(0, $examined->rows());
    }

    public function testResultsBelowTheBaselineAreNoiseAndStopAtZero(): void
    {
        $pdo = $this->pdo(4);
        $examined = RowsExamined::install($pdo);
        $pdo->selfCost = 1; // the snapshots became cheaper than when the baseline was taken
        $examined->start();
        $examined->stop();

        self::assertSame(0, $examined->rows());
        self::assertSame(4, $examined->baseline());
    }

    public function testEachWindowStartsFromItsOwnSnapshot(): void
    {
        $pdo = $this->pdo(2);
        $examined = RowsExamined::install($pdo);
        foreach ([10, 25] as $reads) {
            $examined->start();
            $pdo->counters['Handler_read_rnd_next'] += $reads;
            $examined->stop();
            self::assertSame($reads, $examined->rows());
        }
    }

    public function testCountersThatCannotBeReadMakeItUnavailableNotZero(): void
    {
        $pdo = $this->pdo();
        $pdo->denied = true;
        $examined = RowsExamined::install($pdo);

        self::assertFalse($examined->available());
        self::assertNull($examined->baseline());
        $examined->start();
        $examined->stop();
        self::assertNull($examined->rows());
    }

    public function testAMissingCounterMakesItUnavailable(): void
    {
        $pdo = $this->pdo();
        unset($pdo->counters['Handler_read_prev']);

        self::assertFalse(RowsExamined::install($pdo)->available());
    }

    public function testAnAnswerThatStopsWorkingAfterTheInstallGivesNullForThatWindow(): void
    {
        $pdo = $this->pdo(4);
        $examined = RowsExamined::install($pdo);
        $examined->start();
        $pdo->denied = true;
        $examined->stop();

        self::assertNull($examined->rows());
        self::assertSame(4, $examined->baseline());
    }

    public function testASessionThatWasReplacedDuringTheWindowGivesNullNotANegativeOrInventedNumber(): void
    {
        $pdo = $this->pdo(4);
        $examined = RowsExamined::install($pdo);
        $examined->start();
        $pdo->counters = array_map(static fn (int $v): int => 0, $pdo->counters); // a reconnect: the counters began again
        $examined->stop();

        self::assertNull($examined->rows());
    }

    public function testAPersistentConnectionIsNotUsed(): void
    {
        $pdo = new FakeStatusPdo(4, [PDO::ATTR_PERSISTENT => true]);

        self::assertFalse(RowsExamined::install($pdo)->available());
    }

    public function testTheUnavailableLedgerSaysNothing(): void
    {
        $examined = RowsExamined::unavailable();
        $examined->start();
        $examined->stop();

        self::assertFalse($examined->available());
        self::assertNull($examined->rows());
        self::assertNull($examined->baseline());
    }

    public function testTheSnapshotsAreNotCountedByRowsReadWhenTakenOutsideItsWindow(): void
    {
        $pdo = $this->pdo(4);
        $reads = RowsRead::install($pdo);
        $examined = RowsExamined::install($pdo);

        $examined->start(); // before RowsRead::start()
        $reads->start();
        $pdo->counters['Handler_read_key'] += 3;
        $reads->stop();
        $examined->stop(); // after RowsRead::stop()

        self::assertSame([], $reads->rows());
        self::assertSame(3, $examined->rows());
    }

    public function testGrowthIsNullWhenAnyCounterWentDown(): void
    {
        $before = array_fill_keys(RowsExamined::COUNTERS, 10);
        $after = array_fill_keys(RowsExamined::COUNTERS, 12);
        self::assertSame(14, RowsExamined::growth($before, $after));

        $after['Handler_read_last'] = 9;
        self::assertNull(RowsExamined::growth($before, $after));
    }
}

/** A PDO that answers `SHOW SESSION STATUS LIKE 'Handler_read%'` from a table the test moves by hand. */
final class FakeStatusPdo extends PDO
{
    /** @var array<string, int> */
    public array $counters;

    public bool $denied = false;

    /** @param array<int, mixed> $options */
    public function __construct(public int $selfCost, array $options = [])
    {
        parent::__construct('sqlite::memory:', null, null, $options + [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
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
        $selects = [];
        foreach ($this->counters as $name => $value) {
            $selects[] = sprintf("select '%s' as n, %d as v", $name, $value);
        }
        $statement = parent::query(implode(' union all ', $selects));
        // The SHOW read something itself; the next snapshot sees it.
        $this->counters['Handler_read_rnd_next'] += $this->selfCost;

        return $statement;
    }
}
