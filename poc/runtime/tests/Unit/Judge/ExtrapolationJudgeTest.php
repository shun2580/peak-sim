<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Judge;

use PeakSim\Runtime\Judge\ExtrapolationJudge;
use PHPUnit\Framework\TestCase;

/**
 * Every expected number is written by hand from the rules of docs/simulator-spec.md 7.4 (A). The routes are built so
 * that each predicts 100 queries, 1000 rows and 5000 bytes; a measured value of exactly that is "inside", and a
 * measured value far away (x2) is "outside". The denominator is counted per quantity (the query count and the examined
 * rows each have their own), so every count is read from `quantities[<q>]`.
 */
final class ExtrapolationJudgeTest extends TestCase
{
    private const AT = 100000;

    /** @return array<string, mixed> */
    private static function ok(float $value): array
    {
        return ['status' => 'predicted', 'fit_status' => 'ok', 'value' => $value, 'a' => 1.0, 'b' => 1.0, 'k' => 1.0];
    }

    /**
     * @param array<string, mixed>|null $queries
     * @param array<string, mixed>|null $rows
     * @param array<string, mixed>|null $memory
     *
     * @return array<string, mixed>
     */
    private static function route(string $name, ?array $queries = null, ?array $rows = null, ?array $memory = null): array
    {
        return ['route' => $name, 'axis' => 'N', 'table' => 'pages', 'static_lower_bound' => 'O(N)', 'quantities' => [
            'queries' => $queries ?? self::ok(100.0),
            'rows_examined' => $rows ?? self::ok(1000.0),
            'peak_memory' => $memory ?? self::ok(5000.0),
        ]];
    }

    /**
     * @param list<array<string, mixed>> $routes
     *
     * @return array<string, mixed>
     */
    private static function predictions(array $routes): array
    {
        return ['schema_version' => '0.1', 'source' => 'fits-1', 'at' => self::AT, 'routes' => $routes];
    }

    /** @return array<string, mixed> */
    private static function cold(string $name, int $queries = 100, int $rows = 1000, int $mem = 5000, string $class = 'ok'): array
    {
        return ['route' => ltrim(substr($name, strpos($name, ' ') + 1)), 'method' => substr($name, 0, (int) strpos($name, ' ')), 'scale' => self::AT, 'axis' => 'N', 'attempt' => 2, 'class' => $class,
            'queries' => $queries, 'rows_examined' => $rows, 'peak_mem' => $mem];
    }

    /**
     * $n routes, the first $inside of which are measured exactly as predicted and the rest at double (outside) for the
     * query count and the examined rows.
     *
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private static function series(int $n, int $inside): array
    {
        $routes = [];
        $attempts = [];
        for ($i = 0; $i < $n; ++$i) {
            $name = sprintf('GET /r%02d', $i);
            $routes[] = self::route($name);
            $attempts[] = $i < $inside ? self::cold($name) : self::cold($name, 200, 2000);
        }

        return [self::predictions($routes), $attempts];
    }

    public function test_the_edges_of_each_tolerance_are_inside_and_just_past_them_are_outside(): void
    {
        $this->assertSame('match', ExtrapolationJudge::compare(105.0, 100.0, 5)['outcome']);
        $this->assertSame('match', ExtrapolationJudge::compare(95.0, 100.0, 5)['outcome']);
        $this->assertSame('mismatch', ExtrapolationJudge::compare(105.01, 100.0, 5)['outcome']);
        $this->assertSame('mismatch', ExtrapolationJudge::compare(94.99, 100.0, 5)['outcome']);
        $this->assertSame('match', ExtrapolationJudge::compare(1200.0, 1000.0, 20)['outcome']);
        $this->assertSame('mismatch', ExtrapolationJudge::compare(1201.0, 1000.0, 20)['outcome']);
        $this->assertSame('match', ExtrapolationJudge::compare(130.0, 100.0, 30)['outcome']);
        $this->assertSame('match', ExtrapolationJudge::compare(70.0, 100.0, 30)['outcome']);
        $this->assertSame('mismatch', ExtrapolationJudge::compare(130.5, 100.0, 30)['outcome']);
    }

    /**
     * Through judge(), so that the tolerance the judge itself uses is what is pinned (not a value handed to compare()).
     * One route measured at 100 queries, 1000 rows and 5000 bytes; the prediction is moved to the edge and just past it.
     * Predicted values are written by hand: +-5% of 100, +-20% of 1000, +-30% of 5000.
     *
     * @return array<string, array{0: string, 1: float, 2: string}>
     */
    public static function toleranceEdges(): array
    {
        return [
            'queries +5%' => ['queries', 105.0, 'match'],
            'queries +5.1%' => ['queries', 105.1, 'mismatch'],
            'queries -5%' => ['queries', 95.0, 'match'],
            'queries -5.1%' => ['queries', 94.9, 'mismatch'],
            'rows_examined +20%' => ['rows_examined', 1200.0, 'match'],
            'rows_examined +20.1%' => ['rows_examined', 1201.0, 'mismatch'],
            'rows_examined -20%' => ['rows_examined', 800.0, 'match'],
            'rows_examined -20.1%' => ['rows_examined', 799.0, 'mismatch'],
            'peak_memory +30%' => ['peak_memory', 6500.0, 'match'],
            'peak_memory +30.1%' => ['peak_memory', 6505.0, 'mismatch'],
            'peak_memory -30%' => ['peak_memory', 3500.0, 'match'],
            'peak_memory -30.1%' => ['peak_memory', 3495.0, 'mismatch'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('toleranceEdges')]
    public function test_judge_uses_five_twenty_and_thirty_percent_as_its_own_tolerance(string $quantity, float $predicted, string $outcome): void
    {
        $route = self::route('GET /edge', $quantity === 'queries' ? self::ok($predicted) : null, $quantity === 'rows_examined' ? self::ok($predicted) : null, $quantity === 'peak_memory' ? self::ok($predicted) : null);

        $r = ExtrapolationJudge::judge(self::predictions([$route]), [self::cold('GET /edge')]);

        $this->assertSame($outcome, $r['routes'][0]['quantities'][$quantity]['outcome']);
        $this->assertSame(['queries' => 5, 'rows_examined' => 20, 'peak_memory' => 30][$quantity], $r['quantities'][$quantity]['tolerance_percent']);
    }

    public function test_the_relative_error_is_divided_by_the_measured_value(): void
    {
        // |150 - 100| / 100 = 0.5, not 50/150
        $this->assertEqualsWithDelta(0.5, ExtrapolationJudge::compare(150.0, 100.0, 5)['relative_error'], 1e-12);
        // |50 - 100| / 100 = 0.5
        $this->assertEqualsWithDelta(0.5, ExtrapolationJudge::compare(50.0, 100.0, 5)['relative_error'], 1e-12);
    }

    public function test_a_measured_zero_is_matched_below_one_half_and_missed_from_one_half(): void
    {
        $below = ExtrapolationJudge::compare(0.49, 0.0, 5);
        $this->assertSame('match', $below['outcome']);
        $this->assertNull($below['relative_error']);
        $this->assertSame('mismatch', ExtrapolationJudge::compare(0.5, 0.0, 5)['outcome']);
        $this->assertSame('match', ExtrapolationJudge::compare(0.0, 0.0, 5)['outcome']);
        $this->assertSame('mismatch', ExtrapolationJudge::compare(3.0, 0.0, 30)['outcome']);
    }

    public function test_four_of_five_inside_is_exactly_eighty_percent_and_passes(): void
    {
        [$p, $a] = self::series(5, 4);

        $r = ExtrapolationJudge::judge($p, $a);

        $this->assertSame('pass', $r['verdict']);
        $this->assertSame(5, $r['quantities']['queries']['denominator']);
        $this->assertSame(5, $r['quantities']['rows_examined']['denominator']);
        $this->assertSame(4, $r['quantities']['queries']['within']);
        $this->assertSame(80.0, $r['quantities']['queries']['percent']);
    }

    public function test_three_of_five_inside_is_sixty_percent_and_fails(): void
    {
        [$p, $a] = self::series(5, 3);

        $this->assertSame('fail', ExtrapolationJudge::judge($p, $a)['verdict']);
    }

    public function test_the_pass_line_is_eighty_percent_not_a_looser_one(): void
    {
        // 79 of 100 is 79%: below the line even though 75% would be enough. 80 of 100 is exactly the line.
        [$p, $a] = self::series(100, 79);
        $below = ExtrapolationJudge::judge($p, $a);
        $this->assertSame(79.0, $below['quantities']['queries']['percent']);
        $this->assertSame('fail', $below['verdict']);

        [$p, $a] = self::series(100, 80);
        $on = ExtrapolationJudge::judge($p, $a);
        $this->assertSame(80.0, $on['quantities']['queries']['percent']);
        $this->assertSame('pass', $on['verdict']);

        // 15 of 19 is 78.9%: also below the line. 16 of 20 is 80%.
        [$p, $a] = self::series(19, 15);
        $this->assertSame('fail', ExtrapolationJudge::judge($p, $a)['verdict']);
        [$p, $a] = self::series(20, 16);
        $this->assertSame('pass', ExtrapolationJudge::judge($p, $a)['verdict']);
    }

    public function test_four_in_the_denominator_is_indeterminate_even_when_all_are_inside(): void
    {
        [$p, $a] = self::series(4, 4);

        $r = ExtrapolationJudge::judge($p, $a);

        $this->assertSame('indeterminate', $r['verdict']);
        $this->assertSame(4, $r['quantities']['queries']['denominator']);
        $this->assertSame(4, $r['quantities']['rows_examined']['denominator']);
    }

    public function test_both_the_query_count_and_the_examined_rows_must_reach_eighty_percent(): void
    {
        $routes = [];
        $attempts = [];
        for ($i = 0; $i < 5; ++$i) {
            $name = sprintf('GET /r%d', $i);
            $routes[] = self::route($name);
            // queries always inside; examined rows inside only for 3 of 5
            $attempts[] = self::cold($name, 100, $i < 3 ? 1000 : 3000);
        }

        $r = ExtrapolationJudge::judge(self::predictions($routes), $attempts);

        $this->assertSame(100.0, $r['quantities']['queries']['percent']);
        $this->assertSame(60.0, $r['quantities']['rows_examined']['percent']);
        $this->assertSame('fail', $r['verdict']);
    }

    public function test_two_unmeasured_of_ten_is_exactly_twenty_percent_and_does_not_make_it_indeterminate(): void
    {
        [$p, $a] = self::series(10, 10);
        $a = array_slice($a, 0, 8); // the last two routes have no cold attempt at the predicted size

        $r = ExtrapolationJudge::judge($p, $a);

        foreach (['queries', 'rows_examined'] as $q) {
            $this->assertSame(2, $r['quantities'][$q]['unmeasured']);
            $this->assertSame(8, $r['quantities'][$q]['denominator']);
        }
        $this->assertSame('pass', $r['verdict']);
    }

    public function test_three_unmeasured_of_ten_is_thirty_percent_and_indeterminate(): void
    {
        [$p, $a] = self::series(10, 10);
        $a = array_slice($a, 0, 7);

        $r = ExtrapolationJudge::judge($p, $a);

        foreach (['queries', 'rows_examined'] as $q) {
            $this->assertSame(3, $r['quantities'][$q]['unmeasured']);
            $this->assertSame(7, $r['quantities'][$q]['denominator']);
        }
        $this->assertSame('indeterminate', $r['verdict']);
    }

    public function test_routes_without_a_formula_are_not_in_the_base_of_the_twenty_percent_line(): void
    {
        // For the examined rows: 7 measured + 3 unmeasured = 10 routes with a formula: 3 of 10 is 30%, over the line.
        // Ten more routes have no formula for the examined rows (insufficient_scales); counting them in the base would
        // make it 3 of 20 = 15% and hide the 30%.
        [$p, $a] = self::series(10, 10);
        $a = array_slice($a, 0, 7);
        for ($i = 0; $i < 10; ++$i) {
            $name = sprintf('GET /few%02d', $i);
            $p['routes'][] = self::route($name, self::ok(100.0), ['status' => 'not_predicted', 'fit_status' => 'insufficient_scales', 'reason' => 'scale 10000: timeout']);
            $a[] = self::cold($name);
        }

        $r = ExtrapolationJudge::judge($p, $a);

        $this->assertSame(10, $r['quantities']['rows_examined']['insufficient_scales']);
        $this->assertSame(10, $r['quantities']['rows_examined']['with_formula']);
        $this->assertSame(3, $r['quantities']['rows_examined']['unmeasured']);
        $this->assertSame('indeterminate', $r['verdict']);

        // And the other side: 2 of 10 with a formula is exactly 20%, so the same ten extra routes change nothing.
        [$p, $a] = self::series(10, 10);
        $a = array_slice($a, 0, 8);
        for ($i = 0; $i < 10; ++$i) {
            $name = sprintf('GET /few%02d', $i);
            $p['routes'][] = self::route($name, self::ok(100.0), ['status' => 'not_predicted', 'fit_status' => 'insufficient_scales', 'reason' => 'scale 10000: timeout']);
            $a[] = self::cold($name);
        }
        $r = ExtrapolationJudge::judge($p, $a);
        $this->assertSame(10, $r['quantities']['rows_examined']['with_formula']);
        $this->assertSame(8, $r['quantities']['rows_examined']['denominator']);
        $this->assertSame('pass', $r['verdict']);
    }

    public function test_a_timeout_at_the_predicted_size_counts_as_unmeasured_with_its_class(): void
    {
        [$p, $a] = self::series(10, 10);
        $a[9] = self::cold('GET /r09', class: 'timeout');

        $r = ExtrapolationJudge::judge($p, $a);

        $last = end($r['routes']);
        foreach (['queries', 'rows_examined', 'peak_memory'] as $q) {
            $this->assertSame('unmeasured', $last['quantities'][$q]['category']);
            $this->assertSame('cold attempt class timeout', $last['quantities'][$q]['reason']);
        }
        $this->assertSame(9, $r['quantities']['queries']['denominator']);
        $this->assertSame(9, $r['quantities']['rows_examined']['denominator']);
    }

    public function test_contradicts_static_and_insufficient_scales_are_left_out_of_that_quantitys_denominator_but_counted_and_listed(): void
    {
        [$p, $a] = self::series(5, 5);
        $p['routes'][] = self::route('GET /contra', ['status' => 'not_predicted', 'fit_status' => 'contradicts_static', 'reason' => 'k 0.1 under O(N)']);
        $p['routes'][] = self::route('GET /few', self::ok(100.0), ['status' => 'not_predicted', 'fit_status' => 'insufficient_scales', 'reason' => 'scale 10000: timeout']);
        $a[] = self::cold('GET /contra');
        $a[] = self::cold('GET /few');

        $r = ExtrapolationJudge::judge($p, $a);

        $this->assertSame('pass', $r['verdict']);
        // 5 ordinary routes + /few for the query count (its examined rows have no formula); 5 + /contra for the examined rows.
        $this->assertSame(6, $r['quantities']['queries']['denominator']);
        $this->assertSame(1, $r['quantities']['queries']['contradicts_static']);
        $this->assertSame(0, $r['quantities']['queries']['insufficient_scales']);
        $this->assertSame(6, $r['quantities']['rows_examined']['denominator']);
        $this->assertSame(0, $r['quantities']['rows_examined']['contradicts_static']);
        $this->assertSame(1, $r['quantities']['rows_examined']['insufficient_scales']);
        $byName = array_column($r['routes'], null, 'route');
        $this->assertSame('contradicts_static', $byName['GET /contra']['quantities']['queries']['category']);
        $this->assertSame('k 0.1 under O(N)', $byName['GET /contra']['quantities']['queries']['reason']);
        $this->assertSame('in_denominator', $byName['GET /contra']['quantities']['rows_examined']['category']);
        $this->assertSame('in_denominator', $byName['GET /few']['quantities']['queries']['category']);
        $this->assertSame('insufficient_scales', $byName['GET /few']['quantities']['rows_examined']['category']);
        $this->assertSame('scale 10000: timeout', $byName['GET /few']['quantities']['rows_examined']['reason']);
        $md = ExtrapolationJudge::toMarkdown($r);
        $this->assertStringContainsString('| GET /contra | queries | contradicts_static | k 0.1 under O(N) |', $md);
        $this->assertStringContainsString('| GET /few | rows_examined | insufficient_scales | scale 10000: timeout |', $md);
    }

    public function test_a_route_the_static_analysis_contradicts_for_the_query_count_still_counts_for_the_examined_rows(): void
    {
        // 8 routes inside for both. 3 routes whose query count contradicts the static analysis, with the examined rows
        // measured far outside (x2). If they were dropped from the examined rows, it would be 8 of 8 = 100%: a pass.
        // Kept, it is 8 of 11 = 72.73%: a fail.
        [$p, $a] = self::series(8, 8);
        for ($i = 0; $i < 3; ++$i) {
            $name = sprintf('GET /contra%d', $i);
            $p['routes'][] = self::route($name, ['status' => 'not_predicted', 'fit_status' => 'contradicts_static', 'reason' => 'k 0.1 under O(N)']);
            $a[] = self::cold($name, 100, 2000);
        }

        $r = ExtrapolationJudge::judge($p, $a);

        $this->assertSame(8, $r['quantities']['queries']['denominator']);
        $this->assertSame(3, $r['quantities']['queries']['contradicts_static']);
        $this->assertSame(11, $r['quantities']['rows_examined']['denominator']);
        $this->assertSame(8, $r['quantities']['rows_examined']['within']);
        $this->assertSame(72.73, $r['quantities']['rows_examined']['percent']);
        $this->assertSame('fail', $r['verdict']);
    }

    /**
     * $n routes. The first $queryInside are measured as predicted for the query count and the first $rowsInside for the
     * examined rows; the rest at double.
     *
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private static function mixedSeries(int $n, int $queryInside, int $rowsInside): array
    {
        $routes = [];
        $attempts = [];
        for ($i = 0; $i < $n; ++$i) {
            $name = sprintf('GET /m%03d', $i);
            $routes[] = self::route($name);
            $attempts[] = self::cold($name, $i < $queryInside ? 100 : 200, $i < $rowsInside ? 1000 : 2000);
        }

        return [self::predictions($routes), $attempts];
    }

    public function test_the_pass_needs_eighty_percent_of_each_quantity_and_seventy_nine_of_either_fails(): void
    {
        [$p, $a] = self::mixedSeries(100, 80, 80);
        $this->assertSame('pass', ExtrapolationJudge::judge($p, $a)['verdict']);

        [$p, $a] = self::mixedSeries(100, 80, 79);
        $r = ExtrapolationJudge::judge($p, $a);
        $this->assertSame(79.0, $r['quantities']['rows_examined']['percent']);
        $this->assertSame('fail', $r['verdict']);

        [$p, $a] = self::mixedSeries(100, 79, 80);
        $r = ExtrapolationJudge::judge($p, $a);
        $this->assertSame(79.0, $r['quantities']['queries']['percent']);
        $this->assertSame('fail', $r['verdict']);
    }

    public function test_the_rows_denominator_under_five_makes_it_indeterminate_while_the_query_count_has_enough(): void
    {
        // 6 routes; the examined rows of 2 of them have no formula (insufficient_scales) => 4 for the rows, 6 for the queries.
        [$p, $a] = self::series(6, 6);
        foreach ([4, 5] as $i) {
            $p['routes'][$i]['quantities']['rows_examined'] = ['status' => 'not_predicted', 'fit_status' => 'insufficient_scales', 'reason' => 'scale 10000: timeout'];
        }

        $r = ExtrapolationJudge::judge($p, $a);

        $this->assertSame(6, $r['quantities']['queries']['denominator']);
        $this->assertSame(4, $r['quantities']['rows_examined']['denominator']);
        $this->assertSame('indeterminate', $r['verdict']);
        $this->assertSame(['rows_examined: the denominator has 4 routes, fewer than 5'], $r['reasons']);
    }

    public function test_the_query_denominator_under_five_makes_it_indeterminate_while_the_rows_have_enough(): void
    {
        [$p, $a] = self::series(6, 6);
        foreach ([0, 1] as $i) {
            $p['routes'][$i]['quantities']['queries'] = ['status' => 'not_predicted', 'fit_status' => 'contradicts_static', 'reason' => 'k 0.1 under O(N)'];
        }

        $r = ExtrapolationJudge::judge($p, $a);

        $this->assertSame(4, $r['quantities']['queries']['denominator']);
        $this->assertSame(6, $r['quantities']['rows_examined']['denominator']);
        $this->assertSame('indeterminate', $r['verdict']);
        $this->assertSame(['queries: the denominator has 4 routes, fewer than 5'], $r['reasons']);
    }

    public function test_more_than_twenty_percent_unmeasured_in_one_quantity_alone_makes_it_indeterminate(): void
    {
        // The examined rows were not collected for 3 of 10 routes (30% of the routes with a formula for it); the
        // query count was collected for all 10. The denominators are 10 for the queries and 7 for the rows.
        [$p, $a] = self::series(10, 10);
        foreach ([0, 1, 2] as $i) {
            $a[$i]['rows_examined'] = null;
        }

        $r = ExtrapolationJudge::judge($p, $a);

        $this->assertSame(10, $r['quantities']['queries']['denominator']);
        $this->assertSame(0, $r['quantities']['queries']['unmeasured']);
        $this->assertSame(7, $r['quantities']['rows_examined']['denominator']);
        $this->assertSame(3, $r['quantities']['rows_examined']['unmeasured']);
        $this->assertSame('indeterminate', $r['verdict']);
        $this->assertSame(['rows_examined: 3 of 10 routes with a formula could not be measured at the predicted size, more than 20%'], $r['reasons']);

        // The same for the query count alone.
        [$p, $a] = self::series(10, 10);
        foreach ([0, 1, 2] as $i) {
            $a[$i]['queries'] = null;
        }
        $r = ExtrapolationJudge::judge($p, $a);
        $this->assertSame('indeterminate', $r['verdict']);
        $this->assertSame(['queries: 3 of 10 routes with a formula could not be measured at the predicted size, more than 20%'], $r['reasons']);

        // Exactly 2 of 10 for one quantity is on the line, not over it.
        [$p, $a] = self::series(10, 10);
        foreach ([0, 1] as $i) {
            $a[$i]['rows_examined'] = null;
        }
        $this->assertSame('pass', ExtrapolationJudge::judge($p, $a)['verdict']);
    }

    public function test_peak_memory_does_not_decide_the_test_and_its_routes_inside_thirty_percent_are_listed(): void
    {
        $routes = [];
        $attempts = [];
        for ($i = 0; $i < 5; ++$i) {
            $name = sprintf('GET /r%d', $i);
            $routes[] = self::route($name);
            // The error is divided by the measured value. Predicted 5000 is inside for a measured m with
            // |5000 - m| <= 0.3 m, i.e. 3846.15 <= m <= 7142.86: 7142, 5000 and 3847 are inside, 7143 and 3846 are not.
            $attempts[] = self::cold($name, 100, 1000, [7142, 7143, 5000, 3847, 3846][$i]);
        }

        $r = ExtrapolationJudge::judge(self::predictions($routes), $attempts);

        $this->assertSame('pass', $r['verdict']);
        $this->assertSame(3, $r['quantities']['peak_memory']['within']);
        $this->assertSame(60.0, $r['quantities']['peak_memory']['percent']);
        $this->assertSame(['GET /r0', 'GET /r2', 'GET /r3'], $r['peak_memory_within_tolerance']);
    }

    public function test_a_route_with_a_memory_fit_that_is_not_ok_still_counts_for_the_two_deciding_quantities(): void
    {
        [$p, $a] = self::series(5, 5);
        $p['routes'][0]['quantities']['peak_memory'] = ['status' => 'not_predicted', 'fit_status' => 'insufficient_scales', 'reason' => 'scale 10000: timeout'];

        $r = ExtrapolationJudge::judge($p, $a);

        $this->assertSame(5, $r['quantities']['queries']['denominator']);
        $this->assertSame(5, $r['quantities']['rows_examined']['denominator']);
        $this->assertSame(4, $r['quantities']['peak_memory']['denominator']);
        $this->assertSame(1, $r['quantities']['peak_memory']['insufficient_scales']);
        $this->assertSame('no_prediction', $r['routes'][0]['quantities']['peak_memory']['outcome']);
    }

    public function test_only_the_cold_attempt_at_the_predicted_size_on_the_routes_axis_is_used(): void
    {
        [$p, $a] = self::series(5, 5);
        $a[] = ['route' => 'r00', 'method' => 'GET', 'scale' => 10000, 'axis' => 'N', 'attempt' => 2, 'class' => 'ok', 'queries' => 1, 'rows_examined' => 1, 'peak_mem' => 1];
        $a[] = ['route' => 'r00', 'method' => 'GET', 'scale' => self::AT, 'axis' => 'N', 'attempt' => 3, 'class' => 'ok', 'queries' => 1, 'rows_examined' => 1, 'peak_mem' => 1];
        $a[] = ['route' => 'r00', 'method' => 'GET', 'scale' => self::AT, 'axis' => 'M', 'attempt' => 2, 'class' => 'ok', 'queries' => 1, 'rows_examined' => 1, 'peak_mem' => 1];

        $r = ExtrapolationJudge::judge($p, $a);

        $this->assertSame(100.0, $r['quantities']['queries']['percent']);
    }

    public function test_examined_rows_that_were_not_collected_make_only_that_quantity_unmeasured(): void
    {
        [$p, $a] = self::series(5, 5);
        $a[0]['rows_examined'] = null;

        $r = ExtrapolationJudge::judge($p, $a);

        $this->assertSame('unmeasured', $r['routes'][0]['quantities']['rows_examined']['category']);
        $this->assertSame('rows_examined was not measured', $r['routes'][0]['quantities']['rows_examined']['reason']);
        $this->assertSame('in_denominator', $r['routes'][0]['quantities']['queries']['category']);
        $this->assertSame(5, $r['quantities']['queries']['denominator']);
        $this->assertSame(4, $r['quantities']['rows_examined']['denominator']);
        // 4 routes in the rows' denominator: it cannot be judged.
        $this->assertSame('indeterminate', $r['verdict']);
    }

    public function test_the_same_input_gives_the_same_output_in_any_input_order(): void
    {
        [$p, $a] = self::series(7, 5);
        $shuffledPredictions = $p;
        $shuffledPredictions['routes'] = array_reverse($p['routes']);

        $first = json_encode(ExtrapolationJudge::judge($p, $a, 'run-2'));
        $second = json_encode(ExtrapolationJudge::judge($shuffledPredictions, array_reverse($a), 'run-2'));

        $this->assertSame($first, $second);
        $this->assertSame($first, json_encode(ExtrapolationJudge::judge($p, $a, 'run-2')));
    }
}
