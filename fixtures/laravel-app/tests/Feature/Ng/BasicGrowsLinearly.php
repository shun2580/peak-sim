<?php

namespace Tests\Feature\Ng;

use Ralder\QueryScaling\Core\EvaluationResult;
use Ralder\QueryScaling\Core\MeasurementSeries;
use Ralder\QueryScaling\Core\ScalingExpectation;
use Ralder\QueryScaling\Core\ScalingViolation;

/**
 * The count must grow on every segment, and by the same amount per added item (an affine line).
 * This is the lower-bound counterpart of the library's atMostSlope(): it fails when the count stays flat.
 * The 10% slope spread is fixed up front; it is not tuned against measurements.
 */
final class BasicGrowsLinearly implements ScalingExpectation
{
    private const MAX_SLOPE_SPREAD = 1.1;

    public function evaluate(MeasurementSeries $series): EvaluationResult
    {
        $scales = $series->scales();
        $counts = $series->counts();
        $violations = [];
        $firstSlope = ($counts[1] - $counts[0]) / ($scales[1] - $scales[0]);

        for ($i = 1, $n = count($scales); $i < $n; $i++) {
            $delta = $counts[$i] - $counts[$i - 1];
            $slope = $delta / ($scales[$i] - $scales[$i - 1]);
            $flat = $delta <= 0;
            $bent = $firstSlope > 0 && ($slope > $firstSlope * self::MAX_SLOPE_SPREAD || $slope < $firstSlope / self::MAX_SLOPE_SPREAD);

            if ($flat || $bent) {
                $violations[] = new ScalingViolation(
                    fromScale: $scales[$i - 1],
                    toScale: $scales[$i],
                    fromCount: $counts[$i - 1],
                    toCount: $counts[$i],
                    observedDelta: (float) $delta,
                    allowedDelta: $firstSlope * ($scales[$i] - $scales[$i - 1]),
                );
            }
        }

        return $violations === [] ? EvaluationResult::passed() : EvaluationResult::failed($violations);
    }

    public function summary(): string
    {
        return 'query count to grow by a constant, positive amount per additional item (linear)';
    }
}
