<?php

namespace Tests\Feature\Ng;

use Ralder\QueryScaling\Core\EvaluationResult;
use Ralder\QueryScaling\Core\MeasurementSeries;
use Ralder\QueryScaling\Core\ScalingExpectation;

/**
 * Wraps another expectation, keeps the measured counts and prints them (stderr),
 * so the measurements can be written into the report even when the assertion passes.
 */
final class BasicRecordingExpectation implements ScalingExpectation
{
    /** @var list<int> */
    public array $scales = [];

    /** @var list<int> */
    public array $counts = [];

    public function __construct(
        private readonly ScalingExpectation $inner,
        private readonly string $label,
    ) {}

    public function evaluate(MeasurementSeries $series): EvaluationResult
    {
        $this->scales = $series->scales();
        $this->counts = $series->counts();

        fwrite(STDERR, sprintf(
            "[measure] queries %s scales=[%s] counts=[%s]\n",
            $this->label,
            implode(',', $this->scales),
            implode(',', $this->counts),
        ));

        return $this->inner->evaluate($series);
    }

    public function summary(): string
    {
        return $this->inner->summary();
    }

    /** Queries added per added item, per segment. */
    public function lastSlope(): float
    {
        $n = count($this->scales);

        return ($this->counts[$n - 1] - $this->counts[$n - 2]) / ($this->scales[$n - 1] - $this->scales[$n - 2]);
    }
}
