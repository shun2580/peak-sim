<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\LabEntry;

use PeakSim\Runtime\Guard\RollbackGuard;
use PeakSim\Runtime\Run\Classification;
use PeakSim\Runtime\Run\QueryCounter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The lab's entry script writes `views` without a rollback (poc/lab/README.md). The tool's measurement, poc/runtime, is
 * not the lab: there every request still runs in a transaction that is rolled back, a request that could not be rolled
 * back is not measured, and `views` is a table like any other. This file ties that down in three ways, so that the
 * lab's looser rule cannot get into the measurement unnoticed:
 *
 *  1. the measurement driver (src/Run/MeasureOne.php) still begins a transaction before the request, rolls it back after
 *     it, and turns every sign of a request that could not be rolled back into "the database can no longer be trusted"
 *     (checked on its code; each mutation below is caught);
 *  2. no file of the measurement refers to the lab's guard or names `views` as an exception (checked on every file of
 *     poc/runtime/src outside LabEntry, poc/runtime/bin; each mutation below is caught);
 *  3. the shared guard functions still treat a write to `views` as they treat any other write, and condition 6 of
 *     poc/runtime/README.md is word for word what it was.
 */
final class RuntimeKeepsRollbackTest extends TestCase
{
    private const RUNTIME = __DIR__ . '/../../..';

    private const DRIVER = self::RUNTIME . '/src/Run/MeasureOne.php';

    private const CONDITION_6 = '6. **Writes are rolled back after every request.** `RollbackGuard` names what cannot be rolled back; those routes are recorded, not measured.';

    /**
     * @return list<string> what is missing from the driver's code
     */
    public static function driverProblems(string $code): array
    {
        $problems = [];
        $last = -1;
        $steps = [
            'a transaction begins' => '$db->beginTransaction();',
            'the request is sent' => '$requester->send($spec);',
            'the level is read' => '$level = $db->transactionLevel();',
            'the transaction is rolled back' => '$db->rollBack();',
        ];
        foreach ($steps as $name => $needle) {
            $count = substr_count($code, $needle);
            if ($count !== 1) {
                $problems[] = sprintf('%s: "%s" appears %d times, not once', $name, $needle, $count);
                continue;
            }
            $at = (int) strpos($code, $needle);
            if ($at < $last) {
                $problems[] = sprintf('%s: out of order', $name);
            }
            $last = max($last, $at);
        }
        $chain = [
            'a loop rolls back what is still open' => 'for ($i = 0; $i < 50 && $db->transactionLevel() > 0; $i++) { $db->rollBack(); }',
            'a schema other than the measurement database stops the run' => 'if ($counter->foreignSchemas() !== []) { throw new MeasureRefused(',
            'what the counter saw is the first problem' => '$problem = $counter->rollbackProblem();',
            'a transaction the request ended is a problem' => 'if ($problem === null && !RollbackGuard::transactionStillOpen($level)) { $problem = ',
            'a table with another row count is a problem' => 'if ($problem === null && ($changed = RollbackGuard::changedTables($before, $after)) !== []) { $problem = ',
            'the problem goes to the classification' => '$problem, $result->redirectPath',
            'a problem stops the run as contaminated' => 'if ($problem !== null) {',
            'the exit of a contaminated database' => 'return self::EXIT_CONTAMINATED;',
        ];
        foreach ($chain as $name => $needle) {
            if (substr_count($code, $needle) !== 1) {
                $problems[] = sprintf('%s: "%s" is not there exactly once', $name, $needle);
            }
        }

        return $problems;
    }

    /**
     * @return list<string> where the lab's looser rule has got into this code
     */
    public static function leaks(string $code): array
    {
        $leaks = [];
        foreach (['LabEntry', 'ViewsOnlyWrites', 'EntryGuard', 'LabWriteRefused'] as $name) {
            if (str_contains($code, $name)) {
                $leaks[] = 'refers to ' . $name;
            }
        }
        if (preg_match('/[\'"`]views[\'"`]/', $code) === 1) {
            $leaks[] = 'names the table `views`';
        }

        return $leaks;
    }

    public function testTheDriverStillWrapsEveryRequest(): void
    {
        self::assertSame([], self::driverProblems(CodeOnly::file(self::DRIVER)));
    }

    /** @return iterable<string, array{string, string}> */
    public static function driverMutations(): iterable
    {
        yield 'the transaction is not begun' => ['$db->beginTransaction();', ''];
        yield 'the rollback is not made' => ['$db->rollBack();', ''];
        yield 'the loop that rolls back is cut short' => ['$i < 50 && $db->transactionLevel() > 0', 'false'];
        yield 'the level is not read' => ['$level = $db->transactionLevel();', '$level = 1;'];
        yield 'a request that ended the transaction is let through' => ['!RollbackGuard::transactionStillOpen($level)', 'false'];
        yield 'a changed table is let through' => ['($changed = RollbackGuard::changedTables($before, $after)) !== []', '($changed = []) !== []'];
        yield 'what the counter saw is dropped' => ['$problem = $counter->rollbackProblem();', '$problem = null;'];
        yield 'another schema is let through' => ['if ($counter->foreignSchemas() !== []) {', 'if (false) {'];
        yield 'the problem is not passed on' => ['if ($problem !== null) {', 'if (false) {'];
        yield 'a contaminated database does not stop the run' => ['return self::EXIT_CONTAMINATED;', 'return self::EXIT_OK;'];
    }

    #[DataProvider('driverMutations')]
    public function testEveryLoosenedDriverIsCaught(string $search, string $replace): void
    {
        $source = (string) file_get_contents(self::DRIVER);
        self::assertGreaterThanOrEqual(1, substr_count($source, $search), 'the mutation does not apply: ' . $search);
        $first = (int) strpos($source, $search);
        $mutated = substr($source, 0, $first) . $replace . substr($source, $first + strlen($search));

        self::assertNotSame([], self::driverProblems(CodeOnly::of($mutated)), 'not caught: ' . $search . ' -> ' . $replace);
    }

    /** @return list<string> */
    private static function measurementFiles(): array
    {
        $files = [];
        $root = (string) realpath(self::RUNTIME);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && !str_contains($file->getPathname(), '/src/LabEntry/')) {
                $files[] = $file->getPathname();
            }
        }
        foreach (glob($root . '/bin/inner/*.php') ?: [] as $file) {
            $files[] = $file;
        }
        sort($files);

        return $files;
    }

    public function testNoFileOfTheMeasurementCarriesTheLabsRule(): void
    {
        $files = self::measurementFiles();
        self::assertGreaterThan(100, count($files), 'the files of the measurement were not found');
        $found = [];
        foreach ($files as $file) {
            $code = CodeOnly::file($file);
            foreach (self::leaks($code) as $leak) {
                $found[] = basename($file) . ' ' . $leak;
            }
        }
        self::assertSame([], $found);
    }

    /** @return iterable<string, array{string}> */
    public static function leakMutations(): iterable
    {
        yield 'the driver takes the lab guard in' => ['use PeakSim\\Runtime\\LabEntry\\ViewsOnlyWrites;'];
        yield 'the rollback guard calls the lab statement rule' => ['$ok = \\PeakSim\\Runtime\\LabEntry\\ViewsOnlyWrites::violation("a", "a", "d", $sql);'];
        yield 'the entry guard in the measurement' => ['new EntryGuard($latch, $db, "mysql");'];
        yield 'views made an exception in the guard' => ["if (\$table === 'views') { return null; }"];
        yield 'views made an exception, double quotes' => ['if ($table === "views") { return null; }'];
        yield 'views made an exception, backticks in SQL text' => ['$allowed = "update `views` set";'];
    }

    #[DataProvider('leakMutations')]
    public function testEveryLeakIsCaught(string $snippet): void
    {
        $guard = (string) file_get_contents(self::RUNTIME . '/src/Guard/RollbackGuard.php');
        $code = CodeOnly::of($guard . "\n" . '<?php ' . $snippet);

        self::assertNotSame([], self::leaks($code), 'not caught: ' . $snippet);
    }

    public function testTheSharedGuardsStillTreatViewsAsAnyOtherTable(): void
    {
        self::assertSame(['views'], RollbackGuard::changedTables(['views' => 5, 'entities' => 3], ['views' => 6, 'entities' => 3]));
        self::assertTrue(RollbackGuard::isWrite('update `views` set `views` = 1 where id = 1'));
        self::assertTrue(RollbackGuard::otherConnectionWrite('audit', 'mysql', 'update `views` set `views` = 1'));
        self::assertFalse(RollbackGuard::transactionStillOpen(0));
        self::assertTrue(RollbackGuard::transactionStillOpen(1));
        foreach (['commit' => 'transaction_control', 'set autocommit = 1' => 'transaction_control', 'truncate table `views`' => 'ddl', 'analyze table `views`' => 'maintenance'] as $sql => $reason) {
            self::assertSame($reason, RollbackGuard::implicitCommitReason($sql), $sql);
        }
    }

    public function testAWriteToViewsIsStillARequestThatIsNotMeasured(): void
    {
        $counter = new QueryCounter('peak_sim_measure_abc_100', 'mysql');
        $counter->start();
        $counter->record('audit', 'update `views` set `views` = 1 where id = 1');
        $counter->stop();
        $problem = $counter->rollbackProblem();
        self::assertSame('other_connection: audit', $problem);

        $decision = Classification::decide(200, null, null, [], $problem);
        self::assertFalse($decision->isMeasured());
        self::assertSame(Classification::NOT_ROLLBACKABLE, $decision->class);

        $changed = Classification::decide(200, null, null, [], 'row_count_changed: views');
        self::assertFalse($changed->isMeasured());
    }

    public function testConditionSixOfTheMeasurementIsUnchanged(): void
    {
        $lines = file(self::RUNTIME . '/README.md', FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        self::assertContains(self::CONDITION_6, $lines);
        self::assertSame(1, count(array_filter($lines, static fn (string $l): bool => str_starts_with($l, '6. '))));
    }
}
