<?php

namespace Tests\Feature\Ng;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * How the peak memory of the six simple /ng routes grows, checked against fixtures/expected.json.
 * Peak memory is noisy: these are reference values, judged by the log-log slope in GrowthRules (D26).
 */
class BasicMemoryTest extends TestCase
{
    use BasicMeasurement;
    use RefreshDatabase;

    private const ITEMS_PER_ORDER = 3;

    /** @return array<string, array{string}> */
    public static function routes(): array
    {
        return BasicQueryScalingTest::routes();
    }

    #[DataProvider('routes')]
    public function test_peak_memory_grows_as_expected_with_orders(string $route): void
    {
        $growth = $this->expectedFor($route)['memory'];

        $scales = [100, 1000, 10000];
        $peaks = $this->measureRoute($route, $scales, fn (int $orders) => $this->seedOrders($orders, self::ITEMS_PER_ORDER));

        $this->assertGrowth('memory', "{$route} N=orders M=".self::ITEMS_PER_ORDER, $this->orderSweepGrowth($growth), $scales, $peaks);
    }

    /** O(N*M): the peak grows with the orders, and with the items per order when the orders are fixed. */
    public function test_nested_peak_memory_grows_with_orders_and_items_per_order(): void
    {
        $route = '/ng/nested';
        $this->assertSame('O(N*M)', $this->expectedFor($route)['memory']);

        $orderScales = [100, 1000, 10000];
        $itemScales = [1, 4, 16];
        $byOrders = $this->measureRoute($route, $orderScales, fn (int $orders) => $this->seedOrders($orders, self::ITEMS_PER_ORDER));
        $byItems = $this->measureRoute($route, $itemScales, fn (int $itemsPerOrder) => $this->seedOrders(500, $itemsPerOrder));

        $this->assertProductGrowth('memory', "{$route} N=orders M=items(N=500)", [$orderScales, $byOrders], [$itemScales, $byItems]);
    }

    /**
     * @param  list<int>  $scales
     * @param  callable(int): void  $populate
     * @return list<int> peak bytes per scale
     */
    private function measureRoute(string $route, array $scales, callable $populate): array
    {
        $peaks = [];
        foreach ($scales as $scale) {
            $this->clearOrders();
            $populate($scale);

            // One request first, so autoloading and container setup are not counted.
            $this->getJson($route)->assertOk();
            $peaks[] = $this->measurePeakMemory(fn () => $this->getJson($route)->assertOk());
        }

        return $peaks;
    }
}
