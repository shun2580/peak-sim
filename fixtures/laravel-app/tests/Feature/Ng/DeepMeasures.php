<?php

namespace Tests\Feature\Ng;

use Closure;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsGrowth;
use Tests\Support\GrowthRules;

/**
 * Shared checks for the deep /ng routes: query counts measured through phpunit-query-scaling,
 * peak memory through MeasuresMemory, and the verdict on both from GrowthRules (D26).
 * Every measurement is logged to STDERR as a "[growth]" line.
 */
trait DeepMeasures
{
    use AssertsGrowth;

    /** Row counts for the query measurements. */
    protected function queryScales(): array
    {
        return [2, 5, 10, 20];
    }

    /** Row counts for the memory measurements. */
    protected function memoryScales(): array
    {
        return [100, 1000, 10000];
    }

    /**
     * The query count is O(N) (GrowthRules), and the added queries per added row stay within $perItem
     * on every segment. With $exact the rate must equal $perItem: that is the coefficient in expected.json.
     *
     * @return list<int> the query counts per scale
     */
    protected function assertQueriesGrowLinearly(
        string $route,
        Closure $populate,
        Closure $run,
        int $perItem,
        bool $exact = false,
        ?array $scales = null,
    ): array {
        $scales ??= $this->queryScales();
        $counts = $this->measureQueryCounts($scales, $populate, $run);

        $this->assertGrowth('queries', $route, 'O(N)', $scales, $counts);

        foreach (GrowthRules::segmentSlopes($scales, $counts) as $slope) {
            if ($exact) {
                $this->assertEqualsWithDelta($perItem, $slope, 1e-9, "{$route}: expected exactly {$perItem} queries per row");
            } else {
                $this->assertLessThanOrEqual($perItem + 1e-9, $slope, "{$route}: expected at most {$perItem} queries per row");
            }
        }

        return $counts;
    }

    /**
     * The peak memory is O(N): the log-log slope between the two largest row counts is at least the linear limit.
     * Peak memory is noisy, so this is a reference value, not an exact claim.
     *
     * @return array<int, int> peak bytes per row count
     */
    protected function assertMemoryGrowsWithRows(string $route, Closure $populate, Closure $run): array
    {
        $scales = $this->memoryScales();

        // Boot the lazy parts of the framework (routes, container) once, off the meter.
        DB::beginTransaction();
        try {
            $populate(2);
            $run();
        } finally {
            DB::rollBack();
        }

        $peaks = [];
        foreach ($scales as $scale) {
            DB::beginTransaction();
            try {
                $populate($scale);
                $peaks[$scale] = $this->measurePeakMemory($run);
            } finally {
                DB::rollBack();
            }
        }

        $this->assertGrowth('memory', $route, 'O(N)', $scales, array_values($peaks));

        return $peaks;
    }
}
