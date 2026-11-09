<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Selfcheck;

use PeakSim\Runtime\Selfcheck\SelfcheckJudge;
use PHPUnit\Framework\TestCase;

/**
 * The comparison of the self-check. Every expected number below is worked out by hand from the rules of the spec
 * (7.1 rule A and B, 7.4 tolerances and pass line, D95, D126, D126b), not read back from the code.
 */
final class SelfcheckJudgeTest extends TestCase
{
    private const AT = 1000;

    /** @return array<string, mixed> */
    private static function est(string $route, float $queries, float $rows, bool $marked = false, ?float $memory = 1000.0): array
    {
        return [
            'route' => $route,
            'complete' => true,
            'status' => 'captured',
            'queries' => $queries,
            'rows_examined' => $rows,
            'rows_examined_upper' => $marked ? $rows * 2 : null,
            'marked' => $marked,
            'peak_memory' => $memory === null ? ['status' => 'unavailable'] : ['status' => 'predicted', 'value' => $memory],
        ];
    }

    /** @return array<string, mixed> a cold record (attempt 2) of "METHOD uri" at the size */
    private static function cold(string $route, float $queries, float $rows, float $memory = 1000.0, int $scale = self::AT): array
    {
        return [
            'route' => $route, 'method' => 'GET', 'scale' => $scale, 'axis' => 'N', 'attempt' => 2, 'class' => 'ok', 'status' => 200,
            'queries' => $queries, 'rows_examined' => $rows, 'peak_mem' => $memory,
        ];
    }

    /** @return array<string, mixed> */
    private static function failed(string $route, string $class, int $status, string $detail = ''): array
    {
        return ['route' => $route, 'method' => 'GET', 'scale' => self::AT, 'axis' => 'N', 'attempt' => 2, 'class' => $class, 'status' => $status, 'detail' => $detail];
    }

    /**
     * @param list<array<string, mixed>> $routes
     *
     * @return array<string, mixed>
     */
    private static function estimate(array $routes): array
    {
        return ['schema_version' => '1', 'candidate' => 'H1_RANGE', 'at' => self::AT, 'app' => 'x', 'routes' => $routes];
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, mixed>
     */
    private static function markedList(array $names): array
    {
        return ['routes' => array_map(static fn (string $n): array => ['route' => $n], $names)];
    }

    /**
     * Five unmarked routes /r1../r5; the estimate is 100 queries and 1000 rows for each.
     *
     * @param list<float> $measuredQueries
     *
     * @return array<string, mixed>
     */
    private static function fiveRoutes(array $measuredQueries, float $measuredRows = 1000.0): array
    {
        $est = [];
        $att = [];
        foreach ($measuredQueries as $i => $q) {
            $name = 'GET /r' . ($i + 1);
            $est[] = self::est($name, 100.0, 1000.0);
            $att[] = self::cold('/r' . ($i + 1), $q, $measuredRows);
        }

        return SelfcheckJudge::judge(self::estimate($est), self::markedList([]), $att, []);
    }

    public function testPassWhenAllWithinTheLowestStandard(): void
    {
        $r = self::fiveRoutes([100, 100, 100, 100, 100]);

        self::assertSame('pass', $r['rules']['B']['verdict']);
        self::assertSame(5, $r['rules']['B']['shares']['all']['queries']['denominator']);
        self::assertSame(5, $r['rules']['B']['shares']['all']['queries']['within_min']);
        self::assertSame(5, $r['rules']['B']['shares']['all']['rows_examined']['within_goal']);
        self::assertSame(100.0, $r['summary']['quantities']['queries']['within_lowest_standard_percent']);
    }

    public function testEightyPercentIsEnoughAndLessIsNot(): void
    {
        // 4 of 5 = 80%: the pass line counts its edge as inside. Query error of 104 against 100 is 4/104 = 3.8%, inside 5%.
        // 106 against 100 is 6/106 = 5.66%, outside.
        $edge = self::fiveRoutes([100, 100, 100, 104, 106]);
        self::assertSame(4, $edge['rules']['B']['shares']['all']['queries']['within_min']);
        self::assertSame('pass', $edge['rules']['B']['verdict']);

        $below = self::fiveRoutes([100, 100, 104, 106, 106]);
        self::assertSame(3, $below['rules']['B']['shares']['all']['queries']['within_min']);
        self::assertSame('fail', $below['rules']['B']['verdict']);
        self::assertStringContainsString('queries: 3 of 5', $below['rules']['B']['reasons'][0]);
    }

    public function testRowsExaminedDecidesToo(): void
    {
        // Rows: estimate 1000 against measured 1300 is 300/1300 = 23.1%, outside 20%. All five outside: fail on rows, queries fine.
        $r = self::fiveRoutes([100, 100, 100, 100, 100], 1300.0);

        self::assertSame('fail', $r['rules']['B']['verdict']);
        self::assertSame(5, $r['rules']['B']['shares']['all']['queries']['within_min']);
        self::assertSame(0, $r['rules']['B']['shares']['all']['rows_examined']['within_min']);
    }

    public function testIndeterminateWithFewerThanFiveRoutes(): void
    {
        $est = [self::est('GET /r1', 100, 1000), self::est('GET /r2', 100, 1000)];
        $att = [self::cold('/r1', 100, 1000), self::cold('/r2', 100, 1000)];
        $r = SelfcheckJudge::judge(self::estimate($est), self::markedList([]), $att, []);

        self::assertSame('indeterminate', $r['rules']['B']['verdict']);
        self::assertStringContainsString('fewer than 5', $r['rules']['B']['reasons'][0]);
    }

    public function testIndeterminateWhenMoreThanTwentyPercentCouldNotBeMeasured(): void
    {
        // 10 routes with an estimate, 7 measured: 3 of 10 = 30% unmeasured, more than 20%.
        $est = [];
        $att = [];
        for ($i = 1; $i <= 10; ++$i) {
            $est[] = self::est('GET /r' . $i, 100, 1000);
            if ($i <= 7) {
                $att[] = self::cold('/r' . $i, 100, 1000);
            }
        }
        $r = SelfcheckJudge::judge(self::estimate($est), self::markedList([]), $att, []);

        self::assertSame('indeterminate', $r['rules']['B']['verdict']);
        self::assertSame(3, $r['rules']['B']['shares']['all']['queries']['unmeasured']);
        self::assertStringContainsString('3 of 10', $r['rules']['B']['reasons'][0]);

        // Exactly 20% (2 of 10) is not more than 20%.
        $att[] = self::cold('/r8', 100, 1000);
        $r = SelfcheckJudge::judge(self::estimate($est), self::markedList([]), $att, []);
        self::assertSame('pass', $r['rules']['B']['verdict']);
    }

    public function testRuleBComparesAMarkedRouteWithTheLargestOfItsColdValuesAndRuleAWithTheSingleOne(): void
    {
        $est = [self::est('GET /m', 500, 500, true)];
        $att = [self::cold('/m', 400, 400)];
        $extra = [[self::cold('/m', 500, 500)], [self::cold('/m', 450, 450)]];
        $r = SelfcheckJudge::judge(self::estimate($est), self::markedList(['GET /m']), $att, $extra);
        $cell = $r['routes'][0]['q']['rows_examined'];

        // Rule A: 500 against 400 is 100/400 = 25%, outside 20%. Rule B: 500 against max(400, 500, 450) = 500 is 0%.
        self::assertSame(400.0, $cell['A']['measured']);
        self::assertFalse($cell['A']['within_min']);
        self::assertEqualsWithDelta(0.25, $cell['A']['relative_error'], 1e-12);
        self::assertSame(500.0, $cell['B']['measured']);
        self::assertTrue($cell['B']['within_min']);
        self::assertTrue($cell['B']['within_goal']);
        self::assertSame(3, $r['routes'][0]['cold_values_used']);
    }

    public function testAnUnmarkedRouteIsComparedWithItsSingleColdValueUnderBothRules(): void
    {
        // Extra tries are not looked at for an unmarked route: they are given here on purpose and must not move rule B.
        $est = [self::est('GET /u', 500, 500)];
        $att = [self::cold('/u', 400, 400)];
        $r = SelfcheckJudge::judge(self::estimate($est), self::markedList([]), $att, [[self::cold('/u', 500, 500)]]);
        $cell = $r['routes'][0]['q']['rows_examined'];

        self::assertSame(400.0, $cell['A']['measured']);
        self::assertSame(400.0, $cell['B']['measured']);
    }

    public function testAMarkedRouteWithATimedOutTryIsNotMeasuredUnderRuleB(): void
    {
        $est = [self::est('GET /m', 500, 500, true)];
        $att = [self::cold('/m', 500, 500)];
        // The 600-second cut is recognised by what the record says, whatever class name it carries (D126b).
        $extra = [[self::failed('/m', 'exception', 500, 'the request ran longer than 600 seconds')], [self::cold('/m', 500, 500)]];
        $r = SelfcheckJudge::judge(self::estimate($est), self::markedList(['GET /m']), $att, $extra);
        $cell = $r['routes'][0]['q']['rows_examined'];

        self::assertSame('in_denominator', $cell['A']['category']);
        self::assertSame('unmeasured', $cell['B']['category']);
        self::assertStringContainsString('600 seconds or more', $cell['B']['reason']);
        self::assertSame('timeout', $r['routes'][0]['failed_tries'][0]['class']);
        self::assertSame(1, $r['rules']['B']['shares']['marked']['rows_examined']['unmeasured']);
    }

    public function testAFailedTryThatIsNotATimeoutIsDroppedAndTheLargestOfTheRestIsUsed(): void
    {
        $est = [self::est('GET /m', 450, 450, true)];
        $att = [self::cold('/m', 450, 450)];
        $extra = [[self::failed('/m', 'validation_422', 422)], [self::cold('/m', 400, 400)]];
        $r = SelfcheckJudge::judge(self::estimate($est), self::markedList(['GET /m']), $att, $extra);
        $cell = $r['routes'][0]['q']['rows_examined'];

        self::assertSame(450.0, $cell['B']['measured']);
        self::assertSame(2, $r['routes'][0]['cold_values_used']);
        self::assertSame(['422'], array_column($r['routes'][0]['failed_tries'], 'class'));
        self::assertSame([['route' => 'GET /m', 'class' => '422']], $r['failed_tries']);
    }

    public function testAMarkedRouteWhoseEveryTryFailedIsUnmeasured(): void
    {
        $est = [self::est('GET /m', 450, 450, true)];
        $att = [self::failed('/m', 'exception', 500, 'placeholder limit')];
        $r = SelfcheckJudge::judge(self::estimate($est), self::markedList(['GET /m']), $att, [[self::failed('/m', 'exception', 500)]]);
        $cell = $r['routes'][0]['q']['queries'];

        self::assertSame('unmeasured', $cell['category']);
        self::assertSame(0, $r['routes'][0]['cold_values_used']);
    }

    public function testAMeasuredZeroMatchesAnEstimateBelowHalf(): void
    {
        $est = [self::est('GET /z', 0.2, 0.2), self::est('GET /y', 0.6, 0.6)];
        $att = [self::cold('/z', 0, 0), self::cold('/y', 0, 0)];
        $r = SelfcheckJudge::judge(self::estimate($est), self::markedList([]), $att, []);
        $z = $r['routes'][1]['q']['queries']['B'];
        $y = $r['routes'][0]['q']['queries']['B'];

        self::assertNull($z['relative_error']);
        self::assertTrue($z['within_min']);
        self::assertFalse($y['within_min']);
        self::assertSame(1, $r['rules']['B']['error_distribution']['queries']['zero_measured_matched']);
        self::assertSame(1, $r['rules']['B']['error_distribution']['queries']['zero_measured_missed']);
    }

    public function testTheErrorDistributionCountsBucketsAndNearestRankPercentiles(): void
    {
        // Measured 100 for every route; the estimates give errors 1, 3, 8, 15, 25 and 40 percent.
        $est = [];
        $att = [];
        foreach ([101, 103, 108, 115, 125, 140] as $i => $e) {
            $est[] = self::est('GET /r' . $i, (float) $e, 1000.0);
            $att[] = self::cold('/r' . $i, 100.0, 1000.0);
        }
        $r = SelfcheckJudge::judge(self::estimate($est), self::markedList([]), $att, []);
        $d = $r['rules']['B']['error_distribution']['queries'];

        self::assertSame(['<=2%' => 1, '<=5%' => 1, '<=10%' => 1, '<=20%' => 1, '<=30%' => 1, '>30%' => 1], $d['percent_buckets']);
        self::assertSame(6, $d['count']);
        self::assertSame(1.0, $d['min_percent']);
        self::assertSame(8.0, $d['median_percent']);   // nearest rank: ceil(0.5 x 6) = 3rd value
        self::assertSame(25.0, $d['p80_percent']);     // ceil(0.8 x 6) = 5th
        self::assertSame(40.0, $d['p90_percent']);     // ceil(0.9 x 6) = 6th
        self::assertSame(40.0, $d['max_percent']);
        // Goal 2% for queries: only the 1% route is inside it; lowest standard 5%: 1% and 3%.
        self::assertSame(1, $r['rules']['B']['shares']['all']['queries']['within_goal']);
        self::assertSame(2, $r['rules']['B']['shares']['all']['queries']['within_min']);
    }

    public function testARouteWithoutAnEstimateIsNotInTheDenominatorAndPeakMemoryIsCountedApart(): void
    {
        $incomplete = self::est('GET /i', 100, 1000, false, null);
        $incomplete['complete'] = false;
        $incomplete['status'] = 'unrecognised_plan';
        $est = [$incomplete, self::est('GET /ok', 100, 1000, false, null)];
        $att = [self::cold('/i', 100, 1000), self::cold('/ok', 100, 1000)];
        $r = SelfcheckJudge::judge(self::estimate($est), self::markedList([]), $att, []);

        self::assertSame(1, $r['rules']['B']['shares']['all']['queries']['no_estimate']);
        self::assertSame(1, $r['rules']['B']['shares']['all']['queries']['denominator']);
        self::assertSame(2, $r['rules']['B']['shares']['all']['peak_memory']['no_estimate']);
        self::assertSame(0, $r['rules']['B']['shares']['all']['peak_memory']['denominator']);
    }

    public function testPeakMemoryUsesItsOwnToleranceAndDoesNotDecide(): void
    {
        $est = [];
        $att = [];
        for ($i = 1; $i <= 5; ++$i) {
            $est[] = self::est('GET /r' . $i, 100, 1000, false, 1000.0);
            // Memory 1400 against 1000 estimated: 400/1400 = 28.6%, inside 30%; 1500: 500/1500 = 33.3%, outside.
            $att[] = self::cold('/r' . $i, 100, 1000, $i <= 3 ? 1400.0 : 1500.0);
        }
        $r = SelfcheckJudge::judge(self::estimate($est), self::markedList([]), $att, []);

        self::assertSame(3, $r['rules']['B']['shares']['all']['peak_memory']['within_min']);
        self::assertSame('pass', $r['rules']['B']['verdict']);
    }

    public function testRecordsOfAnotherSizeAreNotUsed(): void
    {
        $est = [self::est('GET /r1', 100, 1000)];
        $att = [self::cold('/r1', 100, 1000, 1000.0, 500)];
        $r = SelfcheckJudge::judge(self::estimate($est), self::markedList([]), $att, []);

        self::assertSame('unmeasured', $r['routes'][0]['q']['queries']['category']);
    }

    public function testTheMarkedFlagOfTheEstimateMustAgreeWithTheMarkedList(): void
    {
        $est = [self::est('GET /m', 100, 1000, true)];
        $att = [self::cold('/m', 100, 1000)];
        $r = SelfcheckJudge::judge(self::estimate($est), self::markedList([]), $att, []);

        self::assertCount(1, $r['problems']);
        self::assertStringContainsString('GET /m', $r['problems'][0]);
    }

    public function testARouteMeasuredButNotEstimatedIsListed(): void
    {
        $est = [self::est('GET /a', 100, 1000)];
        $att = [self::cold('/a', 100, 1000), self::cold('/b', 7, 7)];
        $r = SelfcheckJudge::judge(self::estimate($est), self::markedList([]), $att, []);

        self::assertSame(['GET /b'], $r['measured_without_estimate']);
    }

    public function testTheSameInputGivesTheSameOutputWhateverTheOrderOfTheRecords(): void
    {
        $est = [self::est('GET /b', 100, 1000, true), self::est('GET /a', 100, 1000)];
        $att = [self::cold('/a', 100, 1000), self::cold('/b', 100, 1000)];
        $extra = [[self::cold('/b', 120, 1200)]];
        $one = SelfcheckJudge::judge(self::estimate($est), self::markedList(['GET /b']), $att, $extra, ['plan_unstable' => 2]);
        $two = SelfcheckJudge::judge(self::estimate(array_reverse($est)), self::markedList(['GET /b']), array_reverse($att), $extra, ['plan_unstable' => 2]);

        self::assertSame(json_encode($one), json_encode($two));
        self::assertSame(['GET /a', 'GET /b'], array_column($one['routes'], 'route'));
    }

    public function testTheSummaryCarriesWhatTheSimulatorShowsAndTheExplainCounts(): void
    {
        $est = [];
        $att = [];
        for ($i = 1; $i <= 5; ++$i) {
            $est[] = self::est('GET /r' . $i, 100, 1000);
            $att[] = self::cold('/r' . $i, 100, 1000);
        }
        $totals = ['statement_types' => 30, 'plan_unstable' => 2, 'plan_changes_at_scale' => 1, 'explain_unavailable' => 3];
        $r = SelfcheckJudge::judge(self::estimate($est), self::markedList([]), $att, [], $totals);

        self::assertSame(self::AT, $r['at']);
        self::assertSame('B', $r['decides_by']);
        self::assertSame(2, $r['summary']['plan_unstable_statements']);
        self::assertSame(3, $r['summary']['explain_unavailable_statements']);
        self::assertSame(5, $r['summary']['routes']);
        self::assertSame(0, $r['summary']['marked_routes']);
        self::assertSame('pass', $r['summary']['verdict']);
        self::assertSame(20, $r['summary']['quantities']['rows_examined']['tolerance_percent']);
        self::assertSame(10, $r['summary']['quantities']['rows_examined']['goal_percent']);
    }
}
