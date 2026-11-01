<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Judge;

use PeakSim\Runtime\Judge\GrowthRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shared vectors in tests/vectors/growth.json are read here and checked against the fixture app's own
 * GrowthRules (read only, never changed): the two implementations must give the same answer on every series, so
 * the copy cannot drift from the rule the fixture expectations were decided with (D26).
 */
final class GrowthRulesTest extends TestCase
{
    private const FIXTURE_RULES = __DIR__ . '/../../../../../fixtures/laravel-app/tests/Support/GrowthRules.php';

    /** @return array<string, mixed> */
    private static function vectors(): array
    {
        $json = file_get_contents(__DIR__ . '/../../vectors/growth.json');
        self::assertIsString($json);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function series(): array
    {
        $cases = [];
        foreach (self::vectors()['series'] as $case) {
            $cases[$case['name']] = [$case];
        }

        return $cases;
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function products(): array
    {
        $cases = [];
        foreach (self::vectors()['products'] as $case) {
            $cases[$case['name']] = [$case];
        }

        return $cases;
    }

    public function test_the_thresholds_are_the_ones_in_the_spec(): void
    {
        $thresholds = self::vectors()['thresholds'];

        $this->assertSame($thresholds['constant_max_slope'], GrowthRules::CONSTANT_MAX_SLOPE);
        $this->assertSame($thresholds['linear_min_slope'], GrowthRules::LINEAR_MIN_SLOPE);
        $this->assertSame($thresholds['max_slope_spread'], GrowthRules::MAX_SLOPE_SPREAD);
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('series')]
    public function test_a_series_is_classified_by_its_log_log_slope(array $case): void
    {
        $this->assertSame($case['class'], GrowthRules::classify($case['scales'], $case['values']));
        $this->assertEqualsWithDelta($case['slope'], GrowthRules::slope($case['scales'], $case['values']), 1e-8);
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('series')]
    public function test_the_segment_slopes_and_the_spread_limit(array $case): void
    {
        $this->assertEqualsWithDelta($case['segment_slopes'], GrowthRules::segmentSlopes($case['scales'], $case['values']), 1e-8);
        $this->assertSame($case['spread_ok'], GrowthRules::spreadWithinLimit($case['scales'], $case['values']));
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('series')]
    public function test_the_verdict_needs_three_points_and_a_steady_slope_for_a_linear_claim(array $case): void
    {
        $this->assertSame($case['verdict'], GrowthRules::verdict($case['scales'], $case['values']));
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('products')]
    public function test_a_product_needs_both_sweeps_to_be_linear(array $case): void
    {
        $this->assertSame(
            $case['verdict'],
            GrowthRules::productVerdict($case['n']['scales'], $case['n']['values'], $case['m']['scales'], $case['m']['values']),
        );
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('series')]
    public function test_the_fixture_rules_give_the_same_answer_on_every_series(array $case): void
    {
        $fixture = self::fixtureRules();

        $this->assertSame($case['class'], $fixture::classify($case['scales'], $case['values']));
        $this->assertEqualsWithDelta($fixture::slope($case['scales'], $case['values']), GrowthRules::slope($case['scales'], $case['values']), 1e-12);
        $this->assertSame($fixture::spreadWithinLimit($case['scales'], $case['values']), GrowthRules::spreadWithinLimit($case['scales'], $case['values']));
    }

    public function test_the_fixture_thresholds_are_the_same_numbers(): void
    {
        $fixture = self::fixtureRules();

        $this->assertSame($fixture::CONSTANT_MAX_SLOPE, GrowthRules::CONSTANT_MAX_SLOPE);
        $this->assertSame($fixture::LINEAR_MIN_SLOPE, GrowthRules::LINEAR_MIN_SLOPE);
        $this->assertSame($fixture::MAX_SLOPE_SPREAD, GrowthRules::MAX_SLOPE_SPREAD);
    }

    public function test_a_series_needs_at_least_two_scales_and_one_value_per_scale(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GrowthRules::slope([100], [5]);
    }

    public function test_the_values_must_match_the_scales_in_number(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GrowthRules::slope([10, 100, 1000], [5, 5]);
    }

    public function test_a_single_segment_has_nothing_to_compare_in_the_spread(): void
    {
        $this->assertTrue(GrowthRules::spreadWithinLimit([100, 1000], [100, 1000]));
    }

    public function test_the_verdict_of_a_too_short_series_is_insufficient_points_not_an_error(): void
    {
        $this->assertSame('insufficient_points', GrowthRules::verdict([100], [5]));
        $this->assertSame('insufficient_points', GrowthRules::verdict([], []));
    }

    public function test_the_spread_check_can_be_switched_off_for_a_series_that_is_not_a_count(): void
    {
        // [100,200,300] -> [100,200,350] wanders; as a count it is inconclusive, without the check it is linear.
        $this->assertSame('inconclusive', GrowthRules::verdict([100, 200, 300], [100, 200, 350]));
        $this->assertSame('measured_ON', GrowthRules::verdict([100, 200, 300], [100, 200, 350], spread: false));
    }

    /** @return class-string */
    private static function fixtureRules(): string
    {
        if (! class_exists(\Tests\Support\GrowthRules::class, false)) {
            require_once self::FIXTURE_RULES;
        }

        return \Tests\Support\GrowthRules::class;
    }
}
