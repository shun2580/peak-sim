<?php

namespace Tests\Support;

use Ralder\QueryScaling\Core\EvaluationResult;
use Ralder\QueryScaling\Core\MeasurementSeries;
use Ralder\QueryScaling\Core\ScalingExpectation;

/**
 * Lets phpunit-query-scaling do the measuring (populate, warm-up, isolation, counting) and keeps the series.
 * It never fails: the verdict on the series belongs to GrowthRules, so every group is judged by the same rule.
 */
final class RecordedSeries implements ScalingExpectation
{
    /** @var list<int> */
    public array $scales = [];

    /** @var list<int> */
    public array $counts = [];

    public function evaluate(MeasurementSeries $series): EvaluationResult
    {
        $this->scales = $series->scales();
        $this->counts = $series->counts();

        return EvaluationResult::passed();
    }

    public function summary(): string
    {
        return 'record the query counts (judged by GrowthRules)';
    }
}
