<?php

namespace Tests\Feature\Ng;

use Ralder\QueryScaling\Core\EvaluationResult;
use Ralder\QueryScaling\Core\MeasurementSeries;
use Ralder\QueryScaling\Core\ScalingExpectation;

/**
 * Wraps a phpunit-query-scaling expectation and keeps the measured series,
 * so a test can log the query counts per scale and check the growth shape.
 */
final class DeepRecordedGrowth implements ScalingExpectation
{
    public ?MeasurementSeries $series = null;

    public function __construct(private readonly ScalingExpectation $inner) {}

    public function evaluate(MeasurementSeries $series): EvaluationResult
    {
        $this->series = $series;

        return $this->inner->evaluate($series);
    }

    public function summary(): string
    {
        return $this->inner->summary();
    }
}
