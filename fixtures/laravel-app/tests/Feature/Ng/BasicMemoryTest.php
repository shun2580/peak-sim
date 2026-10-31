<?php

namespace Tests\Feature\Ng;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * How the peak memory of the six simple /ng routes grows, checked against fixtures/expected.json.
 * Peak memory is noisy: these are reference values, classified by a coarse log-log slope (see BasicMeasurement).
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

        $this->logMemory("{$route} N=orders M=".self::ITEMS_PER_ORDER, $scales, $peaks);
        $this->assertMemoryClass($growth, $scales, $peaks);
    }

    /** O(N*M): with the orders fixed, the peak also grows with the items per order. */
    public function test_nested_peak_memory_grows_with_items_per_order(): void
    {
        $route = '/ng/nested';
        $this->assertSame('O(N*M)', $this->expectedFor($route)['memory']);

        $scales = [1, 4, 16];
        $peaks = $this->measureRoute($route, $scales, fn (int $itemsPerOrder) => $this->seedOrders(500, $itemsPerOrder));

        $this->logMemory("{$route} M-sweep N=500", $scales, $peaks);
        $this->assertSame('linear', $this->memoryClass($scales, $peaks));
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

    /** @param  list<int>  $scales  @param  list<int>  $peaks */
    private function assertMemoryClass(string $growth, array $scales, array $peaks): void
    {
        $expected = match ($growth) {
            'O(1)' => 'constant',
            'O(N)', 'O(N*M)' => 'linear',
            default => $this->fail("unexpected memory growth {$growth} in expected.json"),
        };

        $this->assertSame($expected, $this->memoryClass($scales, $peaks), "expected memory {$growth}");
    }
}
