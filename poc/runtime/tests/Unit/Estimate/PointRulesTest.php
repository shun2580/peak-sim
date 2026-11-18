<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Estimate;

use PeakSim\Runtime\Estimate\RowEstimator;
use PeakSim\Runtime\Simulate\Load\EstimateFormulas;
use PHPUnit\Framework\TestCase;

/**
 * The rules P1 and P2 of the row estimate of a marked type (`docs/research/extrapolation-test-3-causes.md` 7.1 and 7.2), on a made-up estimate.
 *
 * P1  a type marked `plan_changes_at_scale` whose plan at N is one table read by a full scan: the point is the rows of that scan.
 * P2  a marked type (either mark) whose rows per run were fitted: the point is not below the value at the largest scale carried along a line through the origin.
 *
 * Every figure is worked out in the comment of its test. The fixtures are in `PointRuleFixtures` (N = 100,000; captured at 100 / 1,000 / 10,000, so the line is x10).
 * A type that no rule moves, a candidate other than H1_RANGE, and a size not above the largest scale give the output they gave before the rules, byte for byte
 * (`golden/point-rules-untouched.json`, made with the code of the commit before the rules).
 */
final class PointRulesTest extends TestCase
{
    /** @return array<string, array<string, mixed>> the types of `GET /moved` by id */
    private static function moved(string $candidate = 'H1_RANGE', ?int $n = null): array
    {
        [$capture, $explain] = PointRuleFixtures::fixture();
        $capture['routes'] = [$capture['routes'][0]];
        $explain['routes'] = [$explain['routes'][0]];
        $out = [];
        foreach (RowEstimator::estimate($capture, $explain, $candidate, $n)['routes'][0]['types'] as $t) {
            $out[$t['id']] = $t;
        }

        return $out;
    }

    public function test_p1_a_full_scan_of_one_table_gives_its_rows_as_the_point_and_keeps_the_fit_as_the_fit(): void
    {
        $t = self::moved()['p1_scan'];
        // the fit is flat at 50 rows; the plan at N is a full scan of 99,000 rows. P2's line would be 50 x 10 = 500, below that.
        $this->assertSame('P1', $t['point_rule']);
        $this->assertSame(['scan_rows' => 99000.0, 'at' => PointRuleFixtures::N], $t['point_rule_input']);
        $this->assertEqualsWithDelta(99000.0, $t['per_exec'], 1e-9);
        $this->assertEqualsWithDelta(50.0, $t['per_exec_h1'], 1e-9);
        $this->assertSame('h1', $t['basis']);
    }

    public function test_p1_is_applied_first_and_p2_can_then_raise_the_point_above_the_scan(): void
    {
        $t = self::moved()['p1_scan_below_fit'];
        // the rows per run at 100 / 1,000 / 10,000 rows are 10 / 100 / 1,000 (a line), so the fit gives 10,000 at N = 100,000; the plan at N is a full scan of 20 rows, which P1 takes as the point.
        // P2 is applied to that: the line from the largest scale is 1,000 x 100,000 / 10,000 = 10,000, above the 20 rows, so P2 has the last word.
        $this->assertSame('P2', $t['point_rule']);
        $this->assertEqualsWithDelta(10000.0, $t['per_exec'], 1e-6);
    }

    public function test_p1_does_not_apply_to_a_join_a_subquery_an_index_read_a_type_marked_only_unstable_an_unmarked_type_or_a_type_without_a_plan(): void
    {
        $types = self::moved();
        foreach (['p1_join', 'p1_subquery', 'p1_ref', 'p1_unstable_only', 'p1_unmarked', 'p1_no_plan'] as $id) {
            $this->assertNotSame('P1', $types[$id]['point_rule'] ?? null, $id);
        }
        // the unmarked type keeps its fit, and carries no rule
        $this->assertArrayNotHasKey('point_rule', $types['p1_unmarked']);
        $this->assertEqualsWithDelta(50.0, $types['p1_unmarked']['per_exec'], 1e-9);
        // a marked type with no plan, or a plan that is not one scan, is raised by P2 only: 50 x 10
        foreach (['p1_join', 'p1_subquery', 'p1_ref', 'p1_no_plan', 'p1_unstable_only'] as $id) {
            $this->assertSame('P2', $types[$id]['point_rule'], $id);
            $this->assertEqualsWithDelta(500.0, $types[$id]['per_exec'], 1e-9, $id);
        }
    }

    public function test_p2_a_marked_type_is_not_below_the_line_from_the_largest_scale(): void
    {
        $types = self::moved();
        // p2_changes: 50 / 500 / 1,000 rows per run, the fit is below the line 1,000 x 100,000 / 10,000 = 10,000 at N
        $this->assertSame('P2', $types['p2_changes']['point_rule']);
        $this->assertSame(['scale' => 10000, 'value' => 1000.0], $types['p2_changes']['point_rule_input']);
        $this->assertEqualsWithDelta(10000.0, $types['p2_changes']['per_exec'], 1e-9);
        $this->assertLessThan(10000.0, $types['p2_changes']['per_exec_h1']);
        // p2_unstable: 3 / 30 / 60 rows per run: the line is 60 x 10 = 600
        $this->assertSame('P2', $types['p2_unstable']['point_rule']);
        $this->assertEqualsWithDelta(600.0, $types['p2_unstable']['per_exec'], 1e-9);
        // the same shape without a mark is left to its fit
        $this->assertArrayNotHasKey('point_rule', $types['p2_unmarked']);
        $this->assertEqualsWithDelta($types['p2_unmarked']['per_exec_h1'], $types['p2_unmarked']['per_exec'], 1e-9);
    }

    public function test_the_upper_side_of_a_moved_type_is_not_below_its_point(): void
    {
        foreach (self::moved() as $id => $t) {
            if (isset($t['per_exec_upper'])) {
                $this->assertGreaterThanOrEqual($t['per_exec'], $t['per_exec_upper'], $id);
            }
        }
        $p1 = self::moved()['p1_scan'];
        $this->assertGreaterThanOrEqual(99000.0, $p1['per_exec_upper']);
    }

    public function test_the_route_is_the_sum_of_the_points_and_a_moved_route_is_marked(): void
    {
        [$capture, $explain] = PointRuleFixtures::fixture();
        $capture['routes'] = [$capture['routes'][0]];
        $explain['routes'] = [$explain['routes'][0]];
        $route = RowEstimator::estimate($capture, $explain, 'H1_RANGE')['routes'][0];
        $sum = 0.0;
        foreach ($route['types'] as $t) {
            $sum += $t['per_exec'] * $t['count_at_n'];
        }
        $this->assertEqualsWithDelta($sum, $route['rows_examined'], 1e-6);
        $this->assertTrue($route['marked']);
    }

    public function test_p2_does_not_apply_at_a_size_that_is_not_above_the_largest_scale(): void
    {
        // at N = 10,000 (the largest captured scale) there is nothing to carry the line past; p2_changes keeps its fit
        $t = self::moved('H1_RANGE', 10000)['p2_changes'];
        $this->assertArrayNotHasKey('point_rule', $t);
        $this->assertEqualsWithDelta($t['per_exec_h1'], $t['per_exec'], 1e-9);
    }

    public function test_the_rules_are_in_h1_range_only(): void
    {
        foreach (['H1', 'H2', 'H3'] as $candidate) {
            foreach (self::moved($candidate) as $id => $t) {
                $this->assertArrayNotHasKey('point_rule', $t, $candidate . ' ' . $id);
            }
        }
        $h1 = self::moved('H1');
        $this->assertEqualsWithDelta($h1['p1_scan']['per_exec_h1'], $h1['p1_scan']['per_exec'], 1e-9);
    }

    public function test_what_no_rule_moves_gives_the_output_it_gave_before_the_rules_byte_for_byte(): void
    {
        $golden = file_get_contents(__DIR__ . '/golden/point-rules-untouched.json');
        $this->assertIsString($golden);
        $now = json_encode(PointRuleFixtures::untouchedOutputs(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $this->assertSame($golden, $now);
        // and the golden does contain what it is meant to hold: marked types (the rules could have moved them), a route with an upper side
        $doc = json_decode($golden, true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($doc['H1_RANGE GET /untouched']['routes'][0]['marked']);
        $this->assertNotNull($doc['H1_RANGE GET /untouched']['routes'][0]['rows_examined_upper']);
    }

    public function test_the_rule_is_carried_by_the_formulas_and_a_reader_gets_the_same_point_at_the_same_size(): void
    {
        [$capture, $explain] = PointRuleFixtures::fixture();
        $capture['routes'] = [$capture['routes'][0]];
        $explain['routes'] = [$explain['routes'][0]];
        $with = RowEstimator::estimate($capture, $explain, 'H1_RANGE', PointRuleFixtures::N, null, true);
        $types = [];
        foreach ($with['routes'][0]['types'] as $t) {
            $types[$t['id']] = $t;
        }
        $this->assertSame(['rule' => 'P1', 'scan_rows' => 99000.0, 'at' => PointRuleFixtures::N], $types['p1_scan']['formula']['point_rule']);
        $this->assertSame(['rule' => 'P2', 'scale' => 10000, 'value' => 1000.0], $types['p2_changes']['formula']['point_rule']);
        $this->assertArrayNotHasKey('point_rule', $types['p2_unmarked']['formula']);

        // the reader of the formulas: at N it gives the point; at 200,000 rows P1 carries the scan (x2) and P2 the line (x2)
        foreach ([PointRuleFixtures::N => 1.0, 200000 => 2.0] as $n => $factor) {
            $rows = self::readerRows($with, $n);
            foreach (['p1_scan', 'p2_changes', 'p2_unstable'] as $id) {
                $this->assertEqualsWithDelta($types[$id]['per_exec'] * $factor, $rows[$id], 1e-6, $id . ' at ' . $n);
            }
            // an unmarked type carries no rule: the reader gives its fit, which at N is the point
            if ($n === PointRuleFixtures::N) {
                $this->assertEqualsWithDelta($types['p2_unmarked']['per_exec'], $rows['p2_unmarked'], 1e-6);
            }
        }
    }

    /**
     * @param array<string, mixed> $estimate an estimate made with the formulas
     *
     * @return array<string, float> rows per run of each type of `GET /moved` as the reader of the formulas puts them at table size $n
     */
    private static function readerRows(array $estimate, int $n): array
    {
        $cost = EstimateFormulas::fromDocument($estimate)->evaluate('GET /moved', $n);
        if ($cost['cost'] !== 'ok') {
            throw new \LogicException('the made-up estimate must be complete');
        }
        $out = [];
        foreach ((array) ($cost['types'] ?? []) as $t) {
            $out[(string) $t['id']] = (float) $t['per_exec'];
        }

        return $out;
    }
}
