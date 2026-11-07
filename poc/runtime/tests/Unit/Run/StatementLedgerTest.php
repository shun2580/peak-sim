<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Run;

use PeakSim\Runtime\Run\StatementLedger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the tap keeps for the statements of a request, grouped by SQL text. Every expected number is written by hand.
 */
final class StatementLedgerTest extends TestCase
{
    public function testStatementsAreGroupedByTextAndAddedUp(): void
    {
        $ledger = new StatementLedger();
        $ledger->record('select * from `pages` where `id` = ?', 3, [10]);
        $ledger->record('delete from `pages` where `id` = ?', 1, [10]);
        $ledger->record('select * from `pages` where `id` = ?', 1, [11]);
        $ledger->record('select * from `pages` where `id` = ?', 8, [12]);
        $ledger->record('delete from `pages` where `id` = ?', 1, [11]);

        self::assertSame(5, $ledger->events());
        self::assertSame(3 + 1 + 1 + 8 + 1, $ledger->rowsSum());
        self::assertSame([
            [
                'sql' => 'select * from `pages` where `id` = ?', 'type' => 'select', 'count' => 3,
                'rows_sum' => 12, 'rows_min' => 1, 'rows_max' => 8, 'unread' => 0,
                'first_bindings' => [10], 'last_bindings' => [12],
            ],
            [
                'sql' => 'delete from `pages` where `id` = ?', 'type' => 'delete', 'count' => 2,
                'rows_sum' => 2, 'rows_min' => 1, 'rows_max' => 1, 'unread' => 0,
                'first_bindings' => [10], 'last_bindings' => [11],
            ],
        ], $ledger->statements(), 'in the order the texts first appeared');
    }

    public function testARunWhoseRowsCouldNotBeReadIsCountedAndKeptOutOfTheSums(): void
    {
        $ledger = new StatementLedger();
        $ledger->record('select 1', null, []);
        $ledger->record('select 1', 4, []);
        $ledger->record('select 1', null, []);

        self::assertSame(3, $ledger->events());
        self::assertSame(2, $ledger->unread());
        self::assertSame(4, $ledger->rowsSum());
        $s = $ledger->statements()[0];
        self::assertSame(3, $s['count']);
        self::assertSame(2, $s['unread']);
        self::assertSame(4, $s['rows_sum']);
        self::assertSame(4, $s['rows_min']);
        self::assertSame(4, $s['rows_max']);
    }

    public function testATextWhoseEveryRunIsUnreadHasNoMinimumOrMaximum(): void
    {
        $ledger = new StatementLedger();
        $ledger->record('select 1', null, []);

        $s = $ledger->statements()[0];
        self::assertNull($s['rows_min'], 'not turned into 0');
        self::assertNull($s['rows_max']);
        self::assertSame(0, $s['rows_sum']);
    }

    public function testAStatementThatReadsNothingHasAMinimumOfZero(): void
    {
        $ledger = new StatementLedger();
        $ledger->record('select 1', 5, []);
        $ledger->record('select 1', 0, []);

        $s = $ledger->statements()[0];
        self::assertSame(0, $s['rows_min']);
        self::assertSame(5, $s['rows_max']);
    }

    public function testASingleRunHasTheSameFirstAndLastBindings(): void
    {
        $ledger = new StatementLedger();
        $ledger->record('select ?', 1, ['a', 7, null]);

        $s = $ledger->statements()[0];
        self::assertSame(['a', 7, null], $s['first_bindings']);
        self::assertSame(['a', 7, null], $s['last_bindings']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function types(): iterable
    {
        yield 'select' => ['select * from `a`', 'select'];
        yield 'upper case and leading space' => ["  \nSELECT 1", 'select'];
        yield 'update' => ['update `a` set `b` = ?', 'update'];
        yield 'delete' => ['delete from `a` where `id` = ?', 'delete'];
        yield 'insert' => ['insert into `a` (`b`) values (?)', 'insert'];
        yield 'a select in parentheses' => ['(select 1) union (select 2)', 'select'];
        yield 'with' => ['with `x` as (select 1) select * from `x`', 'with'];
        yield 'set' => ['set names utf8mb4', 'set'];
        yield 'nothing' => ['', ''];
        yield 'no word' => ['1 + 1', ''];
    }

    #[DataProvider('types')]
    public function testTheTypeIsTheFirstWord(string $sql, string $expected): void
    {
        self::assertSame($expected, StatementLedger::typeOf($sql));
    }

    public function testThePlanIsAskedForForTheStatementsExplainTakes(): void
    {
        foreach (['select', 'with', 'update', 'delete', 'insert', 'replace'] as $type) {
            self::assertTrue(StatementLedger::canExplain($type), $type);
        }
        foreach (['set', 'show', 'savepoint', 'create', ''] as $type) {
            self::assertFalse(StatementLedger::canExplain($type), $type);
        }
    }

    public function testABoundValueIsWrittenAsItIsExceptWhenItIsLongOrNotAScalar(): void
    {
        self::assertNull(StatementLedger::showBinding(null));
        self::assertSame(5, StatementLedger::showBinding(5));
        self::assertSame(1.5, StatementLedger::showBinding(1.5));
        self::assertTrue(StatementLedger::showBinding(true));
        self::assertSame('abc', StatementLedger::showBinding('abc'));
        self::assertSame(str_repeat('x', 200), StatementLedger::showBinding(str_repeat('x', 200)), '200 characters is kept whole');
        self::assertSame(str_repeat('x', 200) . '…(+5)', StatementLedger::showBinding(str_repeat('x', 205)));
        self::assertSame('あい…(+3)', StatementLedger::showBinding('あいうえお', 2), 'counted in characters, not bytes');
        self::assertSame('2026-11-08 01:02:03', StatementLedger::showBinding(new \DateTimeImmutable('2026-11-08 01:02:03')));
        self::assertSame('[stdClass]', StatementLedger::showBinding(new \stdClass()));
        self::assertSame('[array]', StatementLedger::showBinding([1]));
    }

    public function testTheDocumentSaysWhatIsLeftOverBetweenTheFigureAndTheSumOfTheStatements(): void
    {
        $ledger = new StatementLedger();
        $ledger->record('select * from `a` where `id` = ?', 6, [1]);
        $ledger->record('select * from `a` where `id` = ?', 4, [2]);
        $ledger->record('set names utf8mb4', 0, []);

        $explains = ['select * from `a` where `id` = ?' => ['first' => ['ok' => true, 'rows' => []], 'last' => ['ok' => false, 'error' => 'boom']]];
        $doc = $ledger->document(['route' => 'r', 'method' => 'GET', 'uri' => '/r', 'scale' => 10, 'axis' => 'N', 'status' => 200, 'class' => 'measured'], true, 0, 10, $explains);

        self::assertSame(1, $doc['tap_version']);
        self::assertSame('cold', $doc['phase']);
        self::assertSame('r', $doc['route']);
        self::assertSame(200, $doc['status']);
        self::assertSame([
            'counters_available' => true, 'baseline' => 0, 'cold_rows_examined' => 10,
            'statement_events' => 3, 'rows_sum' => 10, 'unaccounted' => 0, 'unread_events' => 0,
        ], $doc['window']);
        self::assertCount(2, $doc['statements']);
        self::assertSame(['ok' => true, 'rows' => []], $doc['statements'][0]['explain_first']);
        self::assertSame(['ok' => false, 'error' => 'boom'], $doc['statements'][0]['explain_last']);
        self::assertNull($doc['statements'][1]['explain_first'], 'a statement that was not explained');
        self::assertNull($doc['statements'][1]['explain_last']);
        self::assertSame([1], $doc['statements'][0]['first_bindings']);
        self::assertSame([2], $doc['statements'][0]['last_bindings']);

        $doc = $ledger->document([], true, 0, 13, []);
        self::assertSame(3, $doc['window']['unaccounted'], 'a figure above the sum leaves something unexplained');
    }

    public function testWhenTheCountersCannotBeReadNoRowsAreClaimed(): void
    {
        $ledger = new StatementLedger();
        $ledger->record('select 1', null, []);
        $doc = $ledger->document([], false, null, null, []);

        self::assertFalse($doc['window']['counters_available']);
        self::assertNull($doc['window']['rows_sum']);
        self::assertNull($doc['window']['unaccounted']);
        self::assertSame(1, $doc['window']['unread_events']);
        self::assertSame(1, $doc['window']['statement_events']);
        self::assertNull($doc['statements'][0]['rows_sum']);
        self::assertNull($doc['statements'][0]['rows_min']);
        self::assertNull($doc['statements'][0]['rows_max']);
        self::assertSame(1, $doc['statements'][0]['count']);
    }

    public function testAFigureThatWasNotMeasuredLeavesNothingToCompare(): void
    {
        $ledger = new StatementLedger();
        $ledger->record('select 1', 2, []);
        $doc = $ledger->document([], true, 0, null, []);

        self::assertSame(2, $doc['window']['rows_sum']);
        self::assertNull($doc['window']['unaccounted']);
    }

    public function testAWindowWithNoStatementsIsAValidDocument(): void
    {
        $doc = (new StatementLedger())->document([], true, 0, 0, []);

        self::assertSame([], $doc['statements']);
        self::assertSame(0, $doc['window']['statement_events']);
        self::assertSame(0, $doc['window']['rows_sum']);
        self::assertSame(0, $doc['window']['unaccounted']);
    }
}
