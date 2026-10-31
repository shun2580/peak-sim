<?php

namespace Tests\Feature\Hidden;

use Closure;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsGrowth;
use Tests\Support\GrowthRules;
use Tests\TestCase;

/**
 * Shared measuring code for the /hidden group.
 *
 * Each scale runs in its own transaction on a freshly seeded orders table (rolled back afterwards),
 * so the row count is exactly the scale. For every phase we record the number of queries and the
 * peak memory above the starting usage. Every measurement is also written to stderr so the test
 * log carries the raw numbers (lines starting with "[measure]").
 *
 * The verdict on a series comes from GrowthRules (D26), the same rule as every other group; this class
 * has no thresholds of its own. Memory is a reference value only (peak usage is noisy).
 */
abstract class HiddenRouteTestCase extends TestCase
{
    use AssertsGrowth;
    use RefreshDatabase;

    private int $queryCount = 0;

    /** The route last passed to measure(); it labels the verdict. */
    private string $measuredRoute = '';

    private bool $counting = false;

    protected function setUp(): void
    {
        parent::setUp();

        DB::listen(function (): void {
            if ($this->counting) {
                $this->queryCount++;
            }
        });
    }

    protected function seedOrders(int $orders): void
    {
        DatabaseSeeder::sized(customers: 20, products: 5, orders: $orders, itemsPerOrder: 2)->run();
    }

    /**
     * @param  list<int>  $scales  ascending row counts
     * @param  array<string, array{work: Closure, prepare?: Closure}>  $phases  run in order at every scale
     * @return array<int, array<string, array{queries: int, memory: int}>>
     */
    protected function measure(string $route, array $scales, array $phases): array
    {
        $this->measuredRoute = $route;

        // Warm up once on a single row so class loading and view compilation are not measured.
        $this->inTransaction(1, function () use ($phases): void {
            foreach ($phases as $phase) {
                isset($phase['prepare']) && $phase['prepare']();
                $phase['work']();
            }
        });

        $results = [];
        foreach ($scales as $n) {
            $this->inTransaction($n, function () use ($route, $n, $phases, &$results): void {
                foreach ($phases as $name => $phase) {
                    isset($phase['prepare']) && $phase['prepare']();

                    $this->queryCount = 0;
                    $this->counting = true;
                    $memory = $this->measurePeakMemory($phase['work']);
                    $this->counting = false;

                    $results[$n][$name] = ['queries' => $this->queryCount, 'memory' => $memory];
                    fwrite(STDERR, sprintf(
                        "[measure] route=%s phase=%s n=%d queries=%d memory_bytes=%d\n",
                        $route, $name, $n, $this->queryCount, $memory,
                    ));
                }
            });
        }

        return $results;
    }

    /** One-phase shortcut: GET the url once per scale. */
    protected function measureGet(string $url, array $scales): array
    {
        return $this->measure($url, $scales, ['request' => [
            'work' => fn () => $this->get($url)->assertOk(),
        ]]);
    }

    private function inTransaction(int $orders, Closure $body): void
    {
        DB::beginTransaction();
        try {
            $this->seedOrders($orders);
            $body();
        } finally {
            DB::rollBack();
        }
    }

    /** @param  'O(1)'|'O(N)'  $expected */
    protected function assertQueryClass(string $expected, array $results, string $phase = 'request'): void
    {
        $series = $this->series($results, $phase, 'queries');

        $this->assertGrowth('queries', "{$this->measuredRoute} [{$phase}]", $expected, array_keys($series), array_values($series));
    }

    /** @param  'O(1)'|'O(N)'  $expected */
    protected function assertMemoryClass(string $expected, array $results, string $phase = 'request'): void
    {
        $series = $this->series($results, $phase, 'memory');

        $this->assertGrowth('memory', "{$this->measuredRoute} [{$phase}]", $expected, array_keys($series), array_values($series));
    }

    /**
     * The same verdict on a second sweep at small sizes, measured by phpunit-query-scaling
     * (a cross-check on the instrument, not a second rule). $atMostPerRow also holds the added queries per added row.
     *
     * @param  'O(1)'|'O(N)'  $expected
     * @param  list<int>  $scales
     */
    protected function assertQueryGrowthAtSmallScales(
        string $route,
        string $expected,
        array $scales = [5, 10, 20],
        ?Closure $populate = null,
        ?Closure $run = null,
        ?Closure $warmup = null,
        bool $allowNoQueries = false,
        ?int $atMostPerRow = null,
    ): void {
        $counts = $this->measureQueryCounts(
            scales: $scales,
            populate: $populate ?? fn (int $scale) => $this->seedOrders($scale),
            run: $run ?? fn () => $this->get($route)->assertOk(),
            warmup: $warmup,
            allowNoQueries: $allowNoQueries,
        );

        $this->assertGrowth('queries', "{$route} [small scales]", $expected, $scales, $counts);

        foreach ($atMostPerRow === null ? [] : GrowthRules::segmentSlopes($scales, $counts) as $slope) {
            $this->assertLessThanOrEqual($atMostPerRow + 1e-9, $slope, "{$route}: expected at most {$atMostPerRow} queries per added row");
        }
    }

    /** @return array<int, int> scale => value */
    protected function series(array $results, string $phase, string $key): array
    {
        return array_map(fn (array $row) => $row[$phase][$key], $results);
    }
}
