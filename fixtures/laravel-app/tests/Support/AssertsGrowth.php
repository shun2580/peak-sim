<?php

namespace Tests\Support;

use Closure;
use Ralder\QueryScaling\Assertions\QueryScalingAssertions;

/**
 * What every group's tests use to judge a measured series: it hands the series to GrowthRules and writes a
 * "[growth]" line to stderr (kind, label, scales, values, slope) so the numbers can be quoted in a report.
 * A series that does not fit fails the test; the expectation is never changed to fit it (D18).
 */
trait AssertsGrowth
{
    use MeasuresMemory;
    use QueryScalingAssertions;

    /**
     * Query counts at each scale, measured by phpunit-query-scaling.
     *
     * @param  list<int>  $scales
     * @return list<int> the count per scale
     */
    protected function measureQueryCounts(
        array $scales,
        Closure $populate,
        Closure $run,
        ?Closure $warmup = null,
        bool $allowNoQueries = false,
    ): array {
        $recorded = new RecordedSeries;

        $this->assertQueryScaling(
            scales: $scales,
            populate: $populate,
            run: $run,
            expectation: $recorded,
            warmup: $warmup,
            allowNoQueries: $allowNoQueries,
        );

        return $recorded->counts;
    }

    /**
     * @param  'O(1)'|'O(N)'  $expected
     * @param  'queries'|'memory'  $kind
     * @param  list<int>  $scales
     * @param  list<int|float>  $values
     */
    protected function assertGrowth(string $kind, string $label, string $expected, array $scales, array $values): void
    {
        $values = array_values($values);
        $this->logGrowth($kind, $label, $scales, $values);

        // Only a query count claimed to be linear has to keep the same slope on every segment; memory is noisy.
        $problem = GrowthRules::check($expected, $scales, $values, spread: $kind === 'queries');

        $this->assertNull($problem, "{$label} ({$kind}): {$problem}");
    }

    /**
     * @param  'queries'|'memory'  $kind
     * @param  array{list<int>, list<int|float>}  $n  scales and values of the sweep over N
     * @param  array{list<int>, list<int|float>}  $m  scales and values of the sweep over M
     */
    protected function assertProductGrowth(string $kind, string $label, array $n, array $m): void
    {
        $this->logGrowth($kind, "{$label} N sweep", $n[0], $n[1]);
        $this->logGrowth($kind, "{$label} M sweep", $m[0], $m[1]);

        $problem = GrowthRules::checkProduct($n, $m, spread: $kind === 'queries');

        $this->assertNull($problem, "{$label} ({$kind}, O(N*M)): {$problem}");
    }

    /**
     * @param  list<int>  $scales
     * @param  list<int|float>  $values
     */
    protected function logGrowth(string $kind, string $label, array $scales, array $values): void
    {
        fwrite(STDERR, sprintf(
            "[growth] %s %s scales=%s values=%s slope=%.3f class=%s\n",
            $kind, $label, implode(',', $scales), implode(',', $values),
            GrowthRules::slope($scales, $values), GrowthRules::classify($scales, $values),
        ));
    }
}
