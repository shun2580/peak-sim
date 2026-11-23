<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Estimate;

use PeakSim\Runtime\Estimate\MarkedRoutes;
use PeakSim\Runtime\Estimate\MarkedRoutesCommand;
use PeakSim\Runtime\Estimate\OffsetChunkFit;
use PeakSim\Runtime\Estimate\RowEstimator;
use PeakSim\Runtime\Judge\CostFit;
use PHPUnit\Framework\TestCase;

/**
 * The mark `offset_chunk_fit` (`OffsetChunkFit`) on made-up captures. Every figure is worked out by hand in the comment of its test.
 *
 * The statements are captured at 100 / 1,000 / 10,000 / 30,000 rows with 10 runs each (so a series of rows per run is the whole story). The held-out error is
 * the formula made from the first three sizes, put at 30,000, against the captured product at 30,000. A series that is flat over the first three sizes gives a
 * flat formula (a = the flat value, b = 0, whatever k), so the held-out error of [10, 10, 10, X] is (10 - X) / X by hand. A series that is exactly a line through
 * the origin is found exactly (k = 1.00 is on the grid), so its held-out error is 0.
 */
final class OffsetChunkFitTest extends TestCase
{
    private const SCALES = [100, 1000, 10000, 30000];

    /**
     * @param list<int|float> $perExec rows per run at the sizes in $scales
     * @param list<int>       $scales
     *
     * @return array<string, mixed>
     */
    private static function type(string $id, string $text, array $perExec, array $scales = self::SCALES): array
    {
        $perScale = [];
        foreach ($scales as $i => $scale) {
            $perScale[(string) $scale] = ['count' => 10, 'texts' => 1, 'rows_sum' => $perExec[$i] * 10, 'rows_min' => 0, 'rows_max' => 0, 'unread' => 0];
        }

        return [
            'id' => $id, 'kind' => 'select', 'pattern' => $text,
            'representative' => ['scale' => max($scales), 'sql' => $text, 'bindings' => []],
            'per_scale' => $perScale,
            'count_fit' => ['fit_status' => 'ok', 'a' => 10.0, 'b' => 0.0, 'k' => 0.0, 'sse' => 0.0, 'scales' => $scales],
        ];
    }

    /** @return array<string, mixed> an estimate type as `RowEstimator` gives it */
    private static function est(string $id, float $count, float $perExec): array
    {
        return ['id' => $id, 'count_at_n' => $count, 'per_exec' => $perExec];
    }

    private const CHUNK = 'select * from `t` order by `t`.`id` asc limit ? offset ?';

    public function test_a_chunked_statement_the_formula_does_not_follow_marks_its_route(): void
    {
        // rows per run 10 / 10 / 10 / 1000: the first three give a flat 10; the product held out is 10 runs x 1,000 = 10,000 against 10 x 10 = 100: e = -0.99.
        $route = ['route' => 'DELETE /chunks', 'statement_types' => [self::type('chunk', self::CHUNK, [10, 10, 10, 1000])]];
        $mark = OffsetChunkFit::ofRoute($route, [self::est('chunk', 4.0, 100.0)]);
        $this->assertSame(1.0, $mark['statements'][0]['share'], 'the only statement of the route');
        $this->assertEqualsWithDelta(-0.99, $mark['statements'][0]['held_out_error'], 1e-9);
        $this->assertEqualsWithDelta(0.99, $mark['score'], 1e-9);
        $this->assertTrue($mark['marked']);
        $this->assertSame(0.10, $mark['goal']);
        $this->assertTrue($mark['statements'][0]['counted']);
        $this->assertNull($mark['statements'][0]['not_counted_because']);
    }

    public function test_a_formula_that_follows_its_statement_does_not_mark_the_route(): void
    {
        // rows per run 200 / 2,000 / 20,000 / 60,000 is the line 2N: the first three find it exactly (k = 1), it is put at 60,000 again, e = 0.
        $route = ['route' => 'GET /chunks', 'statement_types' => [self::type('chunk', self::CHUNK, [200, 2000, 20000, 60000])]];
        $mark = OffsetChunkFit::ofRoute($route, [self::est('chunk', 4.0, 100.0)]);
        $this->assertEqualsWithDelta(0.0, $mark['statements'][0]['held_out_error'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $mark['score'], 1e-9);
        $this->assertFalse($mark['marked']);
        $this->assertTrue($mark['statements'][0]['counted'], 'counted, and it adds nothing');
    }

    public function test_the_score_is_the_share_of_the_route_times_the_error_so_a_small_share_does_not_mark(): void
    {
        $chunk = self::type('chunk', self::CHUNK, [10, 10, 10, 1000]); // e = -0.99 as above
        $other = self::type('other', 'select 1', [5, 5, 5, 5]);
        $route = ['route' => 'GET /mixed', 'statement_types' => [$chunk, $other]];
        // the chunked statement reads 4 x 100 = 400 rows at N; the other 3,600. Route 4,000: share 0.1, score 0.1 x 0.99 = 0.099, just below 0.10.
        $below = OffsetChunkFit::ofRoute($route, [self::est('chunk', 4.0, 100.0), self::est('other', 36.0, 100.0)]);
        $this->assertEqualsWithDelta(0.1, $below['statements'][0]['share'], 1e-12);
        $this->assertEqualsWithDelta(0.099, $below['score'], 1e-9);
        $this->assertFalse($below['marked']);
        // the other reads 3,500: route 3,900, share 400 / 3,900 = 0.102564..., score x 0.99 = 0.101538..., at or above 0.10.
        $above = OffsetChunkFit::ofRoute($route, [self::est('chunk', 4.0, 100.0), self::est('other', 35.0, 100.0)]);
        $this->assertEqualsWithDelta(400 / 3900, $above['statements'][0]['share'], 1e-12);
        $this->assertEqualsWithDelta(400 / 3900 * 0.99, $above['score'], 1e-9);
        $this->assertTrue($above['marked']);
        $this->assertCount(1, $above['statements'], 'only the statement with an offset clause is listed');
    }

    public function test_a_statement_captured_at_fewer_than_four_sizes_gives_no_mark_however_badly_it_bends(): void
    {
        $three = self::type('chunk', self::CHUNK, [10, 10, 1000], [100, 1000, 10000]);
        $route = ['route' => 'GET /three', 'statement_types' => [$three]];
        $mark = OffsetChunkFit::ofRoute($route, [self::est('chunk', 4.0, 100.0)]);
        $this->assertNull($mark['statements'][0]['held_out_error']);
        $this->assertFalse($mark['statements'][0]['counted']);
        $this->assertSame('captured_at_fewer_than_four_sizes', $mark['statements'][0]['not_counted_because']);
        $this->assertSame(0.0, $mark['score']);
        $this->assertFalse($mark['marked']);
        $this->assertNull(OffsetChunkFit::heldOutError($three['per_scale']));
    }

    public function test_a_statement_run_once_is_a_page_and_is_not_counted(): void
    {
        // a bend that would mark the route (as in the first test), but the statement runs once at N: `limit 20 offset 0`
        $route = ['route' => 'GET /page', 'statement_types' => [self::type('page', 'select * from `t` limit ? offset ?', [10, 10, 10, 1000])]];
        $once = OffsetChunkFit::ofRoute($route, [self::est('page', 1.0, 100.0)]);
        $this->assertFalse($once['statements'][0]['counted']);
        $this->assertSame('fewer_than_two_runs', $once['statements'][0]['not_counted_because']);
        $this->assertFalse($once['marked']);
        $this->assertEqualsWithDelta(-0.99, $once['statements'][0]['held_out_error'], 1e-9, 'the error is still shown');
        // twice is a repeated read and counts
        $twice = OffsetChunkFit::ofRoute($route, [self::est('page', 2.0, 100.0)]);
        $this->assertTrue($twice['statements'][0]['counted']);
        $this->assertTrue($twice['marked']);
    }

    public function test_a_route_with_no_offset_statement_has_no_entry(): void
    {
        $route = ['route' => 'GET /plain', 'statement_types' => [self::type('a', 'select * from `t` where `id` = ? limit ?', [10, 10, 10, 1000]), self::type('b', 'select * from `offsets` where `x` = ?', [10, 10, 10, 1000])]];
        $this->assertNull(OffsetChunkFit::ofRoute($route, [self::est('a', 4.0, 100.0), self::est('b', 4.0, 100.0)]));
        $this->assertFalse(OffsetChunkFit::hasOffset($route['statement_types'][1]), 'a table called offsets is not an offset clause');
    }

    public function test_the_offset_clause_is_found_in_the_text_of_the_pattern_or_of_the_representative_and_in_any_letter_case(): void
    {
        $this->assertTrue(OffsetChunkFit::hasOffset(['pattern' => 'select 1 LIMIT ? OFFSET ?']));
        $this->assertTrue(OffsetChunkFit::hasOffset(['pattern' => 'select 1', 'representative' => ['sql' => 'select 1 limit 100 offset 6000']]));
        $this->assertFalse(OffsetChunkFit::hasOffset(['pattern' => 'select 1', 'representative' => ['sql' => 'select 1 limit 100']]));
        $this->assertFalse(OffsetChunkFit::hasOffset(['pattern' => 'select 1']));
    }

    public function test_a_route_with_an_unavailable_type_is_not_scored(): void
    {
        $route = ['route' => 'GET /incomplete', 'statement_types' => [self::type('chunk', self::CHUNK, [10, 10, 10, 1000])]];
        $mark = OffsetChunkFit::ofRoute($route, [self::est('chunk', 4.0, 100.0), ['id' => 'gone', 'unavailable' => 'no_rows_read_in_capture']]);
        $this->assertNull($mark['statements'][0]['share']);
        $this->assertSame('route_incomplete', $mark['statements'][0]['not_counted_because']);
        $this->assertFalse($mark['marked']);
    }

    public function test_a_captured_product_of_zero_gives_no_error(): void
    {
        $this->assertNull(OffsetChunkFit::heldOutError(self::type('z', self::CHUNK, [3, 3, 3, 0])['per_scale']));
    }

    /**
     * @param array<int, array{0: int, 1: int|float}> $points size => [runs, rows per run]; a size with no runs read has rows per run 0 and every run unread
     *
     * @return array<string, mixed>
     */
    private static function perScaleOf(array $points): array
    {
        $perScale = [];
        foreach ($points as $scale => [$count, $perExec]) {
            $perScale[(string) $scale] = ['count' => $count, 'texts' => 1, 'rows_sum' => $count * $perExec, 'rows_min' => 0, 'rows_max' => 0, 'unread' => $perExec === 0 ? $count : 0];
        }

        return $perScale;
    }

    public function test_a_size_where_no_run_was_read_is_left_out_of_the_rows_per_run_fit_as_the_estimate_leaves_it_out(): void
    {
        // Five sizes. At 100 the statement did not run (count 0, so no rows per run: the size is not there for the rows). Rows per run at 1,000 / 10,000 / 30,000 /
        // 60,000 are a flat 500: the three before the last give a flat 500, put at 60,000 it is 500 again, so the rows factor is exactly 1.
        // The count is 0 at 100 and 10 at the four sizes after it; the count is fitted on all five sizes, as the estimate fits it (a size with no runs has a count of 0).
        $perScale = self::perScaleOf([100 => [0, 0], 1000 => [10, 500], 10000 => [10, 500], 30000 => [10, 500], 60000 => [10, 500]]);
        $countFit = CostFit::fit([100, 1000, 10000, 30000], [0.0, 10.0, 10.0, 10.0]);
        $putCount = CostFit::evaluate($countFit['a'], $countFit['b'], $countFit['k'], 60000);
        // product put = putCount x 500; captured = 10 x 500: e = putCount / 10 - 1
        $e = OffsetChunkFit::heldOutError($perScale);
        $this->assertEqualsWithDelta($putCount / 10 - 1, $e, 1e-9);
        // with a rows per run of 0 put in for the size where nothing ran (what the first version did) the rows fit is another formula and e is another figure
        $zeroFit = CostFit::fit([100, 1000, 10000, 30000], [0.0, 500.0, 500.0, 500.0]);
        $withZero = (CostFit::evaluate($countFit['a'], $countFit['b'], $countFit['k'], 60000) * CostFit::evaluate($zeroFit['a'], $zeroFit['b'], $zeroFit['k'], 60000) - 5000.0) / 5000.0;
        $this->assertGreaterThan(0.01, abs($withZero - $e), 'the two ways give different figures on this statement, so the test tells them apart');
    }

    public function test_the_rows_per_run_stand_on_fewer_than_four_sizes_when_runs_that_read_nothing_are_left_out(): void
    {
        // The count is on five sizes; rows per run exist at three of them (100 and 1,000 read nothing): three points, no held-out error, whatever the count does.
        $perScale = self::perScaleOf([100 => [4, 0], 1000 => [4, 0], 10000 => [10, 20000], 30000 => [10, 60000], 60000 => [10, 120000]]);
        $this->assertNull(OffsetChunkFit::heldOutError($perScale));
        $route = ['route' => 'GET /few', 'statement_types' => [['id' => 'chunk', 'kind' => 'select', 'pattern' => self::CHUNK, 'representative' => null, 'per_scale' => $perScale]]];
        $mark = OffsetChunkFit::ofRoute($route, [self::est('chunk', 4.0, 100.0)]);
        $this->assertSame('captured_at_fewer_than_four_sizes', $mark['statements'][0]['not_counted_because']);
        $this->assertFalse($mark['marked']);
    }

    public function test_nothing_read_at_the_last_size_gives_no_error(): void
    {
        $this->assertNull(OffsetChunkFit::heldOutError(self::perScaleOf([100 => [10, 5], 1000 => [10, 5], 10000 => [10, 5], 30000 => [10, 0]])));
    }

    /**
     * @param string $text the text of the chunked statement
     *
     * @return array{array<string, mixed>, array<string, mixed>} capture and explain
     */
    private static function app(string $text): array
    {
        $capture = ['capture_version' => 1, 'app' => 'demo', 'scales' => self::SCALES, 'representative_scale' => 30000, 'routes' => [
            ['route' => 'DELETE /chunks', 'status' => 'captured', 'statement_types' => [
                self::type('chunk', $text, [10, 10, 10, 1000]),
                self::type('other', 'select 1', [5, 5, 5, 5]),
            ]],
            ['route' => 'GET /plain', 'status' => 'captured', 'statement_types' => [self::type('plain', 'select 2', [5, 5, 5, 5])]],
        ]];

        return [$capture, ['explain_version' => 1, 'scale' => 240000, 'small_scale' => 30000, 'routes' => []]];
    }

    public function test_the_estimate_carries_the_mark_on_the_routes_with_an_offset_statement_under_h1_range_only(): void
    {
        [$capture, $explain] = self::app(self::CHUNK);
        $est = RowEstimator::estimate($capture, $explain, RowEstimator::H1_RANGE);
        $byRoute = array_column($est['routes'], null, 'route');
        $this->assertArrayNotHasKey(OffsetChunkFit::MARK, $byRoute['GET /plain'], 'a route with no offset statement carries no entry');
        $m = $byRoute['DELETE /chunks'][OffsetChunkFit::MARK];
        $chunk = array_values(array_filter($byRoute['DELETE /chunks']['types'], static fn (array $t): bool => $t['id'] === 'chunk'))[0];
        // the share is the statement's rows at N over the route's rows at N, both from the estimate's own figures; the held-out error is -0.99 as above
        $share = $chunk['per_exec'] * $chunk['count_at_n'] / $byRoute['DELETE /chunks']['rows_examined'];
        $this->assertEqualsWithDelta($share, $m['statements'][0]['share'], 1e-12);
        $this->assertEqualsWithDelta(-0.99, $m['statements'][0]['held_out_error'], 1e-9);
        $this->assertEqualsWithDelta($share * 0.99, $m['score'], 1e-9);
        $this->assertSame($m['score'] >= 0.10, $m['marked']);
        foreach ([RowEstimator::H1, RowEstimator::H2, RowEstimator::H3] as $candidate) {
            foreach (RowEstimator::estimate($capture, $explain, $candidate)['routes'] as $route) {
                $this->assertArrayNotHasKey(OffsetChunkFit::MARK, $route, $candidate . ' is as it was');
            }
        }
    }

    public function test_the_mark_changes_no_figure_of_the_estimate_and_no_other_mark(): void
    {
        [$capture, $explain] = self::app(self::CHUNK);
        [$plain, ] = self::app('select * from `t` order by `t`.`id` asc limit ?'); // the same figures; no offset clause in the text
        $with = RowEstimator::estimate($capture, $explain, RowEstimator::H1_RANGE);
        $without = RowEstimator::estimate($plain, $explain, RowEstimator::H1_RANGE);
        foreach ($with['routes'] as &$route) {
            unset($route[OffsetChunkFit::MARK]);
            foreach ($route['types'] as &$type) {
                unset($type['pattern']);
            }
            unset($type);
        }
        unset($route);
        foreach ($without['routes'] as &$route) {
            $this->assertArrayNotHasKey(OffsetChunkFit::MARK, $route);
            foreach ($route['types'] as &$type) {
                unset($type['pattern']);
            }
            unset($type);
        }
        unset($route);
        $this->assertSame($without, $with);
    }

    public function test_a_route_whose_statements_the_estimate_cannot_give_is_not_marked(): void
    {
        [$capture, $explain] = self::app(self::CHUNK);
        $capture['routes'][0]['statement_types'][1]['count_fit'] = ['fit_status' => 'insufficient_scales'];
        $est = RowEstimator::estimate($capture, $explain, RowEstimator::H1_RANGE);
        $m = array_column($est['routes'], null, 'route')['DELETE /chunks'][OffsetChunkFit::MARK];
        $this->assertFalse($m['marked']);
        $this->assertSame('route_incomplete', $m['statements'][0]['not_counted_because']);
    }

    public function test_the_list_of_marked_routes_names_the_marked_ones_with_their_counted_statements(): void
    {
        [$capture, $explain] = self::app(self::CHUNK);
        $est = RowEstimator::estimate($capture, $explain, RowEstimator::H1_RANGE);
        $list = MarkedRoutes::offsetChunkFit($est);
        $this->assertSame('offset_chunk_fit', $list['mark']);
        $this->assertSame(240000, $list['at']);
        $this->assertSame(1, $list['routes_with_offset_statements']);
        $marked = array_column($est['routes'], null, 'route')['DELETE /chunks'][OffsetChunkFit::MARK]['marked'];
        $this->assertSame($marked ? ['DELETE /chunks'] : [], array_column($list['routes'], 'route'));
        $this->assertSame($marked ? 1 : 0, $list['marked_count']);
        // the estimate of another candidate has no entry: an empty list, not an error
        $none = MarkedRoutes::offsetChunkFit(RowEstimator::estimate($capture, $explain, RowEstimator::H1));
        $this->assertSame(0, $none['routes_with_offset_statements']);
        $this->assertSame([], $none['routes']);
    }

    public function test_the_command_adds_the_list_only_when_it_is_given_an_estimate(): void
    {
        [$capture, $explain] = self::app(self::CHUNK);
        $dir = sys_get_temp_dir() . '/peak-offset-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/explain.json', json_encode(['scale' => 240000, 'routes' => []]));
        file_put_contents($dir . '/estimate.json', json_encode(RowEstimator::estimate($capture, $explain, RowEstimator::H1_RANGE)));
        file_put_contents($dir . '/bad.json', '{not json');
        try {
            $run = static function (array $args): array {
                $out = fopen('php://memory', 'w+');
                $err = fopen('php://memory', 'w+');
                $code = (new MarkedRoutesCommand($out, $err))->run($args);
                rewind($out);
                rewind($err);

                return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
            };
            [$code, $plain] = $run(['--explain=' . $dir . '/explain.json']);
            $this->assertSame(0, $code);
            $this->assertArrayNotHasKey('offset_chunk_fit', json_decode($plain, true), 'without --estimate the output is as it was');
            [$code, $out] = $run(['--explain=' . $dir . '/explain.json', '--estimate=' . $dir . '/estimate.json']);
            $this->assertSame(0, $code);
            $doc = json_decode($out, true);
            $this->assertSame(1, $doc['offset_chunk_fit']['routes_with_offset_statements']);
            unset($doc['offset_chunk_fit']);
            $this->assertSame(json_decode($plain, true), $doc, 'the list of the EXPLAIN marks is the same either way');
            foreach ([['--estimate=' . $dir . '/estimate.json'], ['--explain=' . $dir . '/explain.json', '--estimate=' . $dir . '/missing.json'], ['--explain=' . $dir . '/explain.json', '--estimate=' . $dir . '/bad.json'], ['--explain=' . $dir . '/explain.json', '--estimate=']] as $args) {
                [$code, $out, $err] = $run($args);
                $this->assertSame(2, $code);
                $this->assertSame('', $out);
                $this->assertStringContainsString('usage: bin/marked-routes', $err);
            }
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
    }
}
