<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Simulate\Load;

use PeakSim\Runtime\Simulate\Load\EstimateFormulas;
use PeakSim\Runtime\Simulate\Load\LoadFailure;
use PHPUnit\Framework\TestCase;

/** The formulas put at a scenario's own size. Every expected figure is worked out by hand from the made-up formulas in {@see Fixtures}. */
final class EstimateFormulasTest extends TestCase
{
    private static function formulas(): EstimateFormulas
    {
        return EstimateFormulas::fromDocument(Fixtures::estimateDocument());
    }

    public function test_a_route_is_put_at_the_size_it_is_given_each_time(): void
    {
        $f = self::formulas();
        // N = 1000: t1 count 2, rows 10 + 100 = 110 each -> 220; t2 count 10, rows 3 each -> 30. queries 12, rows 250
        $a = $f->evaluate('GET /a', 1000);
        $this->assertSame('ok', $a['cost']);
        $this->assertEqualsWithDelta(12.0, $a['queries'], 1e-9);
        $this->assertEqualsWithDelta(250.0, $a['rows'], 1e-9);
        // N = 5000: t1 2 x (10 + 500) = 1020; t2 count 50 x 3 = 150. queries 52, rows 1170 (not the figures of N = 1000)
        $b = $f->evaluate('GET /a', 5000);
        $this->assertEqualsWithDelta(52.0, $b['queries'], 1e-9);
        $this->assertEqualsWithDelta(1170.0, $b['rows'], 1e-9);
    }

    public function test_the_types_are_listed_heaviest_first_with_their_marks(): void
    {
        $a = self::formulas()->evaluate('GET /a', 1000);
        $this->assertSame(['t1', 't2'], array_column($a['types'], 'id'));
        $this->assertEqualsWithDelta(220.0, $a['types'][0]['rows'], 1e-9);
        $this->assertEqualsWithDelta(30.0, $a['types'][1]['rows'], 1e-9);
        $this->assertSame(['plan_changes_at_scale' => false, 'plan_unstable' => false], $a['types'][0]['marks']);
        $this->assertNull($a['rows_upper'], 'an unmarked route has no upper side');
        $this->assertFalse($a['marked']);
    }

    public function test_the_upper_side_keeps_the_ratio_of_the_estimate_and_holds_a_value_when_the_ratio_is_undefined(): void
    {
        // N = 300: u1 rows 300 x count 1; upper 5 x 300 = 1,500. u2 rows 0 x count 3 = 0; upper held 40 x 3 = 120. u3 7 x 1, both sides.
        $m = self::formulas()->evaluate('POST /m', 300);
        $this->assertTrue($m['marked']);
        $this->assertEqualsWithDelta(5.0, $m['queries'], 1e-9);
        $this->assertEqualsWithDelta(307.0, $m['rows'], 1e-9);
        $this->assertEqualsWithDelta(1627.0, $m['rows_upper'], 1e-9);
        $this->assertSame('explain', $m['sources']['rows_upper']);
        // the same route at N = 1000: rows 1007, upper 5,000 + 120 + 7
        $m2 = self::formulas()->evaluate('POST /m', 1000);
        $this->assertEqualsWithDelta(1007.0, $m2['rows'], 1e-9);
        $this->assertEqualsWithDelta(5127.0, $m2['rows_upper'], 1e-9);
    }

    public function test_the_peak_memory_is_put_at_the_size_or_says_why_it_is_not(): void
    {
        $f = self::formulas();
        $this->assertEqualsWithDelta(3000.0, $f->evaluate('GET /a', 1000)['peak_memory'], 1e-9);
        $this->assertSame('predicted', $f->evaluate('GET /a', 1000)['peak_memory_status']);
        $m = $f->evaluate('POST /m', 300);
        $this->assertNull($m['peak_memory']);
        $this->assertSame('not_predicted', $m['peak_memory_status']);
    }

    public function test_the_size_is_marked_against_the_sizes_the_rows_were_measured_at(): void
    {
        $f = self::formulas();
        $this->assertSame('measured', $f->evaluate('GET /a', 1000)['n_mark']);
        $this->assertSame('interpolated', $f->evaluate('GET /a', 5000)['n_mark']);
        $this->assertSame('extrapolated', $f->evaluate('GET /a', 20000)['n_mark']);
        $this->assertSame('extrapolated', $f->evaluate('GET /a', 50)['n_mark']);
    }

    public function test_a_cost_that_cannot_be_given_is_unknown_with_a_reason_and_never_zero(): void
    {
        $f = self::formulas();
        foreach ([
            ['GET /nopred', 1000, 'no_estimate'],
            ['GET /inc', 1000, 'incomplete:count_formula:insufficient_scales'],
            ['GET /axisless', null, 'no_axis_table'],
            ['GET /a', null, 'table_count_missing:pages'],
        ] as [$route, $n, $reason]) {
            $r = $f->evaluate($route, $n);
            $this->assertSame('unknown', $r['cost'], $route);
            $this->assertSame($reason, $r['unknown_reason'], $route);
            $this->assertNull($r['queries'], $route);
            $this->assertNull($r['rows'], $route);
            $this->assertNull($r['rows_upper'], $route);
            $this->assertSame([], $r['types'], $route);
        }
    }

    public function test_an_estimate_without_formulas_stops_the_reading_with_25(): void
    {
        $doc = Fixtures::estimateDocument();
        unset($doc['route_index']);
        $this->expectLoadFailure(25, $doc);
    }

    public function test_a_type_without_a_formula_stops_the_reading_with_25(): void
    {
        $doc = Fixtures::estimateDocument();
        unset($doc['routes'][0]['types'][0]['formula']);
        $this->expectLoadFailure(25, $doc);
    }

    public function test_an_estimate_of_candidate_h2_stops_the_reading_because_the_point_is_h1(): void
    {
        $doc = Fixtures::estimateDocument();
        $doc['candidate'] = 'H2';
        $this->expectLoadFailure(25, $doc);
    }

    /** @param array<string, mixed> $doc */
    private function expectLoadFailure(int $code, array $doc): void
    {
        try {
            EstimateFormulas::fromDocument($doc);
            $this->fail('the reading should have stopped');
        } catch (LoadFailure $e) {
            $this->assertSame($code, $e->exitCode);
        }
    }
}
