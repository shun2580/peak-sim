<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Backtest;

/**
 * The naive estimate the simulator is compared with (simulator-spec.md section 7.3):
 *
 *     utilization(target) = utilization(calibration scenario) * (sum of rps of the target / sum of rps of that scenario)
 *
 * It ignores how the data grows and how the mix of routes changes; only the total request rate scales the load.
 *
 * Which calibration scenario it uses: the one with the largest sum of rps. If the sums are the same, the one with the
 * larger observed utilization. A tie in a sum is a difference below TIE_TOLERANCE of the larger sum, so floating-point
 * noise does not pick the scenario.
 */
final class NaiveEstimate
{
    /** Two sums of rps closer than this share of the larger one are the same sum. */
    public const TIE_TOLERANCE = 1e-12;

    /**
     * @param array<string, float|int> $rps route => requests per second (a route that is not listed has rps 0)
     */
    public static function rpsTotal(array $rps): float
    {
        $total = 0.0;
        foreach ($rps as $route => $value) {
            if (!is_int($value) && !is_float($value) || !is_finite((float) $value) || $value < 0) {
                throw new \InvalidArgumentException('the rps of "' . $route . '" is not a number of 0 or more');
            }
            $total += (float) $value;
        }

        return $total;
    }

    /**
     * @param list<array{id: string, rps_total: float, utilization: float}> $calibrations
     *
     * @return array{utilization: float, basis_id: string, basis_utilization: float, basis_rps_total: float, target_rps_total: float}
     */
    public static function estimate(array $calibrations, float $targetRpsTotal): array
    {
        if ($calibrations === []) {
            throw new \InvalidArgumentException('the naive estimate needs at least one calibration scenario');
        }
        if (!is_finite($targetRpsTotal) || $targetRpsTotal < 0) {
            throw new \InvalidArgumentException('the sum of rps of the target is not a number of 0 or more');
        }
        $basis = null;
        foreach ($calibrations as $calibration) {
            if ($basis === null || self::prefers($calibration, $basis)) {
                $basis = $calibration;
            }
        }
        if ($basis['rps_total'] <= 0.0) {
            throw new \InvalidArgumentException(
                'the calibration scenario "' . $basis['id'] . '" has a sum of rps of 0: the ratio is undefined',
            );
        }

        return [
            'utilization' => $basis['utilization'] * ($targetRpsTotal / $basis['rps_total']),
            'basis_id' => $basis['id'],
            'basis_utilization' => $basis['utilization'],
            'basis_rps_total' => $basis['rps_total'],
            'target_rps_total' => $targetRpsTotal,
        ];
    }

    /**
     * @param array{id: string, rps_total: float, utilization: float} $candidate
     * @param array{id: string, rps_total: float, utilization: float} $current
     */
    private static function prefers(array $candidate, array $current): bool
    {
        $bigger = max($candidate['rps_total'], $current['rps_total']);
        if (abs($candidate['rps_total'] - $current['rps_total']) > $bigger * self::TIE_TOLERANCE) {
            return $candidate['rps_total'] > $current['rps_total'];
        }

        return $candidate['utilization'] > $current['utilization'];
    }
}
