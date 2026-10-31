<?php

namespace Tests\Feature\Hidden;

use Closure;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ralder\QueryScaling\Assertions\QueryScalingAssertions;
use Tests\Support\MeasuresMemory;
use Tests\TestCase;

/**
 * Shared measuring code for the /hidden group.
 *
 * Each scale runs in its own transaction on a freshly seeded orders table (rolled back afterwards),
 * so the row count is exactly the scale. For every phase we record the number of queries and the
 * peak memory above the starting usage. Every measurement is also written to stderr so the test
 * log carries the raw numbers (lines starting with "[measure]").
 *
 * Classification rules are fixed here, up front, and are not tuned per route:
 * - queries: O(1) when the count is identical at every scale; O(N) when it rises by at least
 *   half a query per added row between the smallest and the largest scale.
 * - memory (reference only, peak usage is noisy): compare the two largest scales (10x the rows);
 *   O(1) when the peak grows less than 2x, O(N) when it grows 5x or more, otherwise "unclear".
 */
abstract class HiddenRouteTestCase extends TestCase
{
    use MeasuresMemory;
    use QueryScalingAssertions;
    use RefreshDatabase;

    private int $queryCount = 0;

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

    protected function assertQueryClass(string $expected, array $results, string $phase = 'request'): void
    {
        $this->assertSame($expected, $this->queryClass($results, $phase), $this->describe($results, $phase, 'queries'));
    }

    protected function assertMemoryClass(string $expected, array $results, string $phase = 'request'): void
    {
        $this->assertSame($expected, $this->memoryClass($results, $phase), $this->describe($results, $phase, 'memory'));
    }

    protected function queryClass(array $results, string $phase): string
    {
        $series = $this->series($results, $phase, 'queries');
        $scales = array_keys($series);
        $first = $scales[0];
        $last = $scales[array_key_last($scales)];

        if (count(array_unique($series)) === 1) {
            return 'O(1)';
        }

        return ($series[$last] - $series[$first]) >= 0.5 * ($last - $first) ? 'O(N)' : 'unclear';
    }

    protected function memoryClass(array $results, string $phase): string
    {
        $series = $this->series($results, $phase, 'memory');
        $scales = array_keys($series);
        $ratio = $series[$scales[count($scales) - 1]] / max(1, $series[$scales[count($scales) - 2]]);

        return match (true) {
            $ratio < 2 => 'O(1)',
            $ratio >= 5 => 'O(N)',
            default => 'unclear',
        };
    }

    /** @return array<int, int> scale => value */
    protected function series(array $results, string $phase, string $key): array
    {
        return array_map(fn (array $row) => $row[$phase][$key], $results);
    }

    private function describe(array $results, string $phase, string $key): string
    {
        return sprintf('%s by row count (%s): %s', $key, $phase, json_encode($this->series($results, $phase, $key)));
    }
}
