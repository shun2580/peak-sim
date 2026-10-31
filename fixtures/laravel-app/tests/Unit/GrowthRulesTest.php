<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\GrowthRules;

/** The thresholds of D26 (docs/spec.md), checked on made-up series so the rules are pinned independently of any route. */
class GrowthRulesTest extends TestCase
{
    public function test_the_thresholds_are_the_ones_in_the_spec(): void
    {
        $this->assertSame(0.2, GrowthRules::CONSTANT_MAX_SLOPE);
        $this->assertSame(0.5, GrowthRules::LINEAR_MIN_SLOPE);
        $this->assertSame(0.10, GrowthRules::MAX_SLOPE_SPREAD);
    }

    /** @return array<string, array{list<int>, list<int|float>, string}> */
    public static function series(): array
    {
        return [
            'flat' => [[100, 1000, 10000], [5, 5, 5], 'O(1)'],
            'flat at zero (a cache hit)' => [[10, 100, 1000], [0, 0, 0], 'O(1)'],
            'linear' => [[100, 1000, 10000], [100, 1000, 10000], 'O(N)'],
            'linear with a fixed part' => [[5, 10, 20], [6, 11, 21], 'O(N)'],
            'sub-linear but clearly growing (slope 0.6)' => [[1000, 10000], [1000, 3981], 'O(N)'],
            'just under the constant limit (slope 0.2)' => [[1000, 10000], [1000, 1584], 'O(1)'],
            'in between (slope 0.35)' => [[1000, 10000], [1000, 2239], 'inconclusive'],
            'only the two largest scales count' => [[100, 1000, 10000], [10, 10, 1000], 'O(N)'],
        ];
    }

    /** @param  list<int>  $scales  @param  list<int|float>  $values */
    #[DataProvider('series')]
    public function test_a_series_is_classified_by_its_log_log_slope(array $scales, array $values, string $class): void
    {
        $this->assertSame($class, GrowthRules::classify($scales, $values));
    }

    public function test_the_slope_is_taken_between_the_two_largest_scales(): void
    {
        $this->assertEqualsWithDelta(1.0, GrowthRules::slope([100, 1000, 10000], [1, 10, 100]), 1e-9);
        $this->assertEqualsWithDelta(0.0, GrowthRules::slope([100, 1000, 10000], [1, 100, 100]), 1e-9);
    }

    public function test_a_value_below_one_counts_as_one(): void
    {
        $this->assertEqualsWithDelta(0.0, GrowthRules::slope([10, 100], [0, 0]), 1e-9);
        $this->assertGreaterThan(0.5, GrowthRules::slope([10, 100], [0, 50]));
    }

    public function test_segment_slopes_are_the_added_amount_per_added_row(): void
    {
        $this->assertEqualsWithDelta([0.01, 0.01], GrowthRules::segmentSlopes([100, 1000, 10000], [2, 11, 101]), 1e-9);
    }

    public function test_the_spread_of_the_segment_slopes_is_limited_to_ten_percent(): void
    {
        // Every segment slope must be within 10% of the first one.
        $this->assertTrue(GrowthRules::spreadWithinLimit([2, 5, 10, 20], [3, 6, 11, 21]));
        $this->assertTrue(GrowthRules::spreadWithinLimit([100, 200, 300], [100, 200, 309]));
        $this->assertFalse(GrowthRules::spreadWithinLimit([100, 200, 300], [100, 200, 312]));
        $this->assertFalse(GrowthRules::spreadWithinLimit([100, 200, 300], [100, 200, 288]));
    }

    public function test_check_accepts_a_series_that_matches_the_expectation(): void
    {
        $this->assertNull(GrowthRules::check('O(1)', [10, 100, 1000], [2, 2, 2]));
        $this->assertNull(GrowthRules::check('O(N)', [5, 10, 20], [6, 11, 21], spread: true));
    }

    public function test_check_reports_a_mismatch_without_bending_the_rule(): void
    {
        $this->assertStringContainsString('expected O(1)', (string) GrowthRules::check('O(1)', [5, 10, 20], [6, 11, 21]));
        $this->assertStringContainsString('expected O(N)', (string) GrowthRules::check('O(N)', [5, 10, 20], [2, 2, 2]));
    }

    public function test_check_calls_the_in_between_zone_inconclusive(): void
    {
        $message = (string) GrowthRules::check('O(N)', [1000, 10000], [1000, 2239]);

        $this->assertStringContainsString('inconclusive', $message);
        $this->assertStringContainsString('not fitted', $message);
    }

    public function test_check_rejects_linear_growth_whose_slope_wanders(): void
    {
        $this->assertNull(GrowthRules::check('O(N)', [100, 200, 300], [100, 200, 300], spread: true));
        $this->assertStringContainsString('spread', (string) GrowthRules::check('O(N)', [100, 200, 300], [100, 200, 350], spread: true));
        $this->assertNull(GrowthRules::check('O(N)', [100, 200, 300], [100, 200, 350]));
    }

    public function test_a_product_needs_both_sweeps_to_grow(): void
    {
        $grows = [[1, 2, 4], [10, 20, 40]];
        $flat = [[1, 2, 4], [10, 10, 10]];

        $this->assertNull(GrowthRules::checkProduct(n: [[10, 20], [100, 200]], m: $grows));
        $this->assertStringContainsString('M sweep', (string) GrowthRules::checkProduct(n: [[10, 20], [100, 200]], m: $flat));
        $this->assertStringContainsString('N sweep', (string) GrowthRules::checkProduct(n: [[10, 20], [100, 100]], m: $grows));
    }
}
