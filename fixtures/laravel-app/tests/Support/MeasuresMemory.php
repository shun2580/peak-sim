<?php

namespace Tests\Support;

/**
 * Measures the peak memory of a callback, so tests can see how it grows with the row count.
 * The numbers are reference values: peak usage is noisy, so compare the growth, not the bytes.
 */
trait MeasuresMemory
{
    /**
     * Peak bytes allocated while $work runs, above the usage at the start.
     *
     * Collect garbage first so earlier measurements do not inflate the baseline,
     * then reset the peak so it only covers $work.
     */
    protected function measurePeakMemory(callable $work): int
    {
        gc_collect_cycles();
        memory_reset_peak_usage();
        $baseline = memory_get_usage();

        $work();

        return memory_get_peak_usage() - $baseline;
    }
}
