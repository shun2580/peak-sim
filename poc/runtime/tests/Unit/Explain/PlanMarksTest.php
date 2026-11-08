<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Explain;

use PeakSim\Runtime\Explain\PlanMarks;
use PHPUnit\Framework\TestCase;

final class PlanMarksTest extends TestCase
{
    /** @return array{tables: list<array<string, mixed>>, signature: string, scan_rows_sum: int|float|null} */
    private static function plan(string $signature, int $rows = 10): array
    {
        return ['tables' => [], 'signature' => $signature, 'scan_rows_sum' => $rows];
    }

    public function testTheSamePlanInEveryStateAndAtBothScalesHasNoMark(): void
    {
        $p = self::plan('t|ref|k');
        $marks = PlanMarks::of(['default' => $p, 'low' => $p, 'high' => $p], [], $p);

        $this->assertFalse($marks['plan_unstable']);
        $this->assertFalse($marks['plan_changes_at_scale']);
        $this->assertNull($marks['explain_unavailable']);
        $this->assertSame([], $marks['unstable_states']);
    }

    public function testARowEstimateThatMovesWithoutTheKeyMovingIsNotUnstable(): void
    {
        $marks = PlanMarks::of(['default' => self::plan('t|ref|k', 10), 'low' => self::plan('t|ref|k', 99), 'high' => self::plan('t|ref|k', 5)], [], self::plan('t|ref|k'));

        $this->assertFalse($marks['plan_unstable']);
    }

    public function testAKeyThatChangesWithTheStateIsUnstableAndNamesTheStates(): void
    {
        $marks = PlanMarks::of(['default' => self::plan('t|ref|a'), 'low' => self::plan('t|ALL|-'), 'high' => self::plan('t|ref|a')], [], self::plan('t|ref|a'));

        $this->assertTrue($marks['plan_unstable']);
        $this->assertSame(['default', 'low', 'high'], $marks['unstable_states']);
        $this->assertFalse($marks['plan_changes_at_scale'], 'unstable is not the same as changing at scale');
    }

    public function testAPlanThatDiffersFromTheSmallScaleInTheDefaultStateChangesAtScale(): void
    {
        $p = self::plan('t|ALL|-');
        $marks = PlanMarks::of(['default' => $p, 'low' => $p, 'high' => $p], [], self::plan('t|range|k'));

        $this->assertTrue($marks['plan_changes_at_scale']);
        $this->assertFalse($marks['plan_unstable']);
    }

    public function testTheComparisonWithTheSmallScaleUsesTheDefaultStateOnly(): void
    {
        $marks = PlanMarks::of(['default' => self::plan('t|ref|k'), 'low' => self::plan('t|ALL|-'), 'high' => self::plan('t|ref|k')], [], self::plan('t|ref|k'));

        $this->assertFalse($marks['plan_changes_at_scale']);
        $this->assertTrue($marks['plan_unstable']);
    }

    public function testAStateThatWasNotTakenIsNeitherTheSameNorDifferent(): void
    {
        $marks = PlanMarks::of(['default' => self::plan('t|ref|k'), 'low' => null, 'high' => self::plan('t|ref|k')], ['low' => 'server_refused: x'], self::plan('t|ref|k'));

        $this->assertFalse($marks['plan_unstable']);
        $this->assertSame(['low'], $marks['unavailable_states']);
        $this->assertNull($marks['explain_unavailable'], 'the default state worked');
    }

    public function testOneStateAloneCannotBeUnstable(): void
    {
        $marks = PlanMarks::of(['default' => self::plan('t|ref|k'), 'low' => null, 'high' => null], [], self::plan('t|ALL|-'));

        $this->assertFalse($marks['plan_unstable']);
        $this->assertTrue($marks['plan_changes_at_scale']);
    }

    public function testNoPlanAtTheTargetIsExplainUnavailableWithItsReason(): void
    {
        $marks = PlanMarks::of(['default' => null, 'low' => null, 'high' => null], ['default' => 'server_refused: Unknown column'], self::plan('t|ref|k'));

        $this->assertSame('server_refused: Unknown column', $marks['explain_unavailable']);
        $this->assertFalse($marks['plan_changes_at_scale']);
        $this->assertFalse($marks['plan_unstable']);
    }

    public function testNoSmallPlanMeansNoComparison(): void
    {
        $marks = PlanMarks::of(['default' => self::plan('t|ref|k')], [], null);

        $this->assertFalse($marks['plan_changes_at_scale']);
    }
}
