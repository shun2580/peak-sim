<?php

namespace Tests\Feature\Ng;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Ralder\QueryScaling\Assertions\QueryScalingAssertions;
use Tests\TestCase;

/**
 * How the query count of the six simple /ng routes grows, checked against fixtures/expected.json.
 * Which contract is asserted (constant / linear) is read from the expected value, not hard-coded per route.
 */
class BasicQueryScalingTest extends TestCase
{
    use BasicMeasurement;
    use QueryScalingAssertions;
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

        $this->assertQueryScaling(
            scales: [5, 10, 20],
            populate: fn (int $orders) => $this->seedOrders($orders, self::ITEMS_PER_ORDER),
            run: fn () => $this->getJson($route)->assertOk(),
            expectation: $this->queryExpectation($growth, "{$route} N=orders M=".self::ITEMS_PER_ORDER),
        );
    }

    /** O(N*M): the count also grows with the items per order, and the slope per item depends on N. */
    public function test_nested_query_count_grows_with_items_per_order_times_orders(): void
    {
        $route = '/ng/nested';
        $this->assertSame('O(N*M)', $this->expectedFor($route)['query']);

        $slopes = [];
        foreach ([10, 20] as $orders) {
            $expectation = $this->queryExpectation('O(N*M)', "{$route} M-sweep N={$orders}");
            $this->assertQueryScaling(
                scales: [1, 2, 4],
                populate: fn (int $itemsPerOrder) => $this->seedOrders($orders, $itemsPerOrder),
                run: fn () => $this->getJson($route)->assertOk(),
                expectation: $expectation,
            );
            $slopes[$orders] = $expectation->lastSlope();
        }

        // N*M, not N+M: doubling the orders doubles the extra queries each added item costs.
        $this->assertEqualsWithDelta(2.0, $slopes[20] / $slopes[10], 0.1);
    }
}
