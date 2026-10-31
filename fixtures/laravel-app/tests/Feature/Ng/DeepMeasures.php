<?php

namespace Tests\Feature\Ng;

use Closure;
use Illuminate\Support\Facades\DB;
use Ralder\QueryScaling\Assertions\QueryScalingAssertions;
use Ralder\QueryScaling\QueryGrowth;
use Tests\Support\MeasuresMemory;

/**
 * Shared checks for the deep /ng routes: query counts through phpunit-query-scaling,
 * peak memory through MeasuresMemory. Every measurement is logged to STDERR as a "[measure]" line.
 */
trait DeepMeasures
{
    use MeasuresMemory;
    use QueryScalingAssertions;

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
     * The query count grows linearly with the row count: every segment adds queries, at the same rate,
     * and no faster than $perItem queries per added row. With $exact the rate must equal $perItem.
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
        $recorded = new DeepRecordedGrowth(QueryGrowth::atMostSlope($perItem, 0));

        $this->assertQueryScaling(
            scales: $scales ?? $this->queryScales(),
            populate: $populate,
            run: $run,
            expectation: $recorded,
        );

        $scalesMeasured = $recorded->series->scales();
        $counts = $recorded->series->counts();
        fwrite(STDERR, sprintf(
            "[measure] queries route=%s scales=%s counts=%s\n",
            $route, implode(',', $scalesMeasured), implode(',', $counts),
        ));

        $firstSlope = null;
        for ($i = 1; $i < count($counts); $i++) {
            $slope = ($counts[$i] - $counts[$i - 1]) / ($scalesMeasured[$i] - $scalesMeasured[$i - 1]);
            $this->assertGreaterThan(0, $slope, "{$route}: the query count must grow between scale {$scalesMeasured[$i - 1]} and {$scalesMeasured[$i]}");
            $firstSlope ??= $slope;
            $this->assertEqualsWithDelta($firstSlope, $slope, 1e-9, "{$route}: the growth must be linear (same queries per added row on every segment)");
            if ($exact) {
                $this->assertEqualsWithDelta($perItem, $slope, 1e-9, "{$route}: expected exactly {$perItem} queries per row");
            }
        }

        return $counts;
    }

    /**
     * The peak memory grows with the row count: it rises at every step, and the last 10x step
     * (1000 -> 10000 rows) at least triples it. Constant memory would stay near 1x.
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

        fwrite(STDERR, sprintf(
            "[measure] memory route=%s scales=%s peak_bytes=%s\n",
            $route, implode(',', $scales), implode(',', $peaks),
        ));

        for ($i = 1; $i < count($scales); $i++) {
            $this->assertGreaterThan($peaks[$scales[$i - 1]], $peaks[$scales[$i]], "{$route}: peak memory must rise from {$scales[$i - 1]} to {$scales[$i]} rows");
        }
        $last = $scales[count($scales) - 1];
        $before = $scales[count($scales) - 2];
        $this->assertGreaterThanOrEqual(3 * $peaks[$before], $peaks[$last], "{$route}: peak memory must at least triple from {$before} to {$last} rows");

        return $peaks;
    }
}
