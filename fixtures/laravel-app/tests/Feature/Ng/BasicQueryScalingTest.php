<?php

namespace Tests\Feature\Ng;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GrowthRules;
use Tests\TestCase;

/**
 * How the query count of the six simple /ng routes grows, checked against fixtures/expected.json.
 * Which growth is asserted (O(1) / O(N)) is read from the expected value, not hard-coded per route.
 * phpunit-query-scaling measures; the verdict comes from GrowthRules.
 */
class BasicQueryScalingTest extends TestCase
{
    use BasicMeasurement;
    use RefreshDatabase;

    private const ITEMS_PER_ORDER = 3;

    /** @return array<string, array{string}> */
    public static function routes(): array
    {
        return [
            '/ng/n-plus-one' => ['/ng/n-plus-one'],
            '/ng/nested' => ['/ng/nested'],
            '/ng/count-in-loop' => ['/ng/count-in-loop'],
            '/ng/unbounded' => ['/ng/unbounded'],
            '/ng/get-then-count' => ['/ng/get-then-count'],
            '/ng/collection-filter' => ['/ng/collection-filter'],
        ];
    }

    #[DataProvider('routes')]
    public function test_query_count_grows_as_expected_with_orders(string $route): void
    {
        $growth = $this->expectedFor($route)['query'];
        $scales = [5, 10, 20];

        $counts = $this->measureQueryCounts(
            scales: $scales,
            populate: fn (int $orders) => $this->seedOrders($orders, self::ITEMS_PER_ORDER),
            run: fn () => $this->getJson($route)->assertOk(),
        );

        $this->assertGrowth('queries', "{$route} N=orders M=".self::ITEMS_PER_ORDER, $this->orderSweepGrowth($growth), $scales, $counts);
    }

    /** O(N*M): the count also grows with the items per order, and the slope per item depends on N. */
    public function test_nested_query_count_grows_with_items_per_order_times_orders(): void
    {
        $route = '/ng/nested';
        $this->assertSame('O(N*M)', $this->expectedFor($route)['query']);

        $orderScales = [5, 10, 20];
        $itemScales = [1, 2, 4];
        $byOrders = $this->measureQueryCounts(
            scales: $orderScales,
            populate: fn (int $orders) => $this->seedOrders($orders, self::ITEMS_PER_ORDER),
            run: fn () => $this->getJson($route)->assertOk(),
        );

        $byItems = [];
        foreach ([10, 20] as $orders) {
            $byItems[$orders] = $this->measureQueryCounts(
                scales: $itemScales,
                populate: fn (int $itemsPerOrder) => $this->seedOrders($orders, $itemsPerOrder),
                run: fn () => $this->getJson($route)->assertOk(),
            );
        }

        $this->assertProductGrowth('queries', "{$route} N=orders M=items", [$orderScales, $byOrders], [$itemScales, $byItems[10]]);

        // N*M, not N+M: doubling the orders doubles the extra queries each added item costs.
        $perItem = array_map(function (array $counts) use ($itemScales): float {
            $segments = GrowthRules::segmentSlopes($itemScales, $counts);

            return $segments[array_key_last($segments)];
        }, $byItems);
        $this->assertEqualsWithDelta(2.0, $perItem[20] / $perItem[10], 0.1);
    }
}
