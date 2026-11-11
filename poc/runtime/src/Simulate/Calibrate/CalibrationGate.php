<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Calibrate;

use InvalidArgumentException;

/**
 * The gate in front of a calibration measurement (simulator-spec.md section 7.4.1): can the set of planned calibration runs tell the two
 * coefficients c_q and c_row apart? It works from the input of the runs alone (the queries and rows of the estimate, and each run's
 * rps and counts of rows): no observed utilization is read, and none exists yet when the gate runs.
 *
 * The degree. The design matrix X has one row per run and two columns: Q_i, the rate of queries of run i, and R_i, its rate of rows. They are
 * the very numbers the fit uses, {@see CalibrationPoint::rates} (the sums of {@see Calibrator::sums} are taken here too). Each column is scaled
 * to length 1 (the columns are not centred: the fit has no intercept), and the degree is the smallest singular value of the scaled X. It is
 * 1 when the two columns are orthogonal (the runs are as different as can be) and 0 when they are proportional (every run has the same
 * ratio of rows to queries, so one coefficient can stand in for the other).
 *
 * With two unit columns the Gram matrix is [[1, c], [c, 1]] and c = Sab / sqrt(Saa * Sbb), so the singular values are sqrt(1 + c) and
 * sqrt(1 - c) (c is not negative: the rates are not). The degree is sqrt(1 - c), and a set with fewer than two runs, or with a column of
 * zeros, has degree 0.
 *
 * The gate is applied to the whole set and to every set that leaves one run out. The smallest of the degrees is the one that counts: the fit
 * is also made, and its width taken, on each set that leaves one run out (spec 6.3), so each of them has to tell the coefficients apart.
 */
final class CalibrationGate
{
    /**
     * A set whose degree is below this is refused. 0.5 is the value the spec gives for the calibration-design gate (simulator-spec.md
     * section 7.4.1, ruling of 2026-11-11; decisions.md D185). It is not fitted from any result.
     */
    public const MIN_DEGREE = 0.5;

    /**
     * @param list<CalibrationPoint> $points
     *
     * @return array{
     *     threshold: float,
     *     passed: bool,
     *     min_degree: float,
     *     min_left_out: string|null,
     *     scenarios: list<array{id: string, query_rate: float, row_rate: float, rows_per_query: float|null, unknown_cost_routes: int}>,
     *     sets: list<array{left_out: string|null, ids: list<string>, degree: float, passed: bool}>
     * }
     */
    public static function evaluate(array $points, float $threshold = self::MIN_DEGREE): array
    {
        $points = array_values($points);
        if ($points === []) {
            throw new InvalidArgumentException('The calibration gate needs at least one calibration run.');
        }
        $ids = array_map(static fn (CalibrationPoint $p): string => $p->id, $points);
        if (count(array_unique($ids)) !== count($ids)) {
            throw new InvalidArgumentException('The calibration runs must have different ids.');
        }

        $sets = [self::set(null, $points, $threshold)];
        foreach ($points as $i => $left) {
            $rest = $points;
            unset($rest[$i]);
            $sets[] = self::set($left->id, array_values($rest), $threshold);
        }

        $min = $sets[0];
        foreach ($sets as $set) {
            if ($set['degree'] < $min['degree']) {
                $min = $set;
            }
        }

        $scenarios = [];
        foreach ($points as $p) {
            $scenarios[] = [
                'id' => $p->id,
                'query_rate' => $p->queryRate,
                'row_rate' => $p->rowRate,
                'rows_per_query' => $p->queryRate > 0.0 ? $p->rowRate / $p->queryRate : null,
                'unknown_cost_routes' => $p->unknownCostRoutes,
            ];
        }

        return [
            'threshold' => $threshold,
            'passed' => $min['degree'] >= $threshold,
            'min_degree' => $min['degree'],
            'min_left_out' => $min['left_out'],
            'scenarios' => $scenarios,
            'sets' => $sets,
        ];
    }

    /**
     * The degree of one set of runs: the smallest singular value of the design matrix with its two columns scaled to length 1.
     *
     * @param list<CalibrationPoint> $points
     */
    public static function degree(array $points): float
    {
        if (count($points) < 2) {
            return 0.0;
        }
        [$saa, $sbb, $sab] = Calibrator::sums(array_values($points));
        if ($saa <= 0.0 || $sbb <= 0.0) {
            return 0.0;
        }
        $c = $sab / sqrt($saa * $sbb);

        return sqrt(max(0.0, 1.0 - $c));
    }

    /**
     * @param list<CalibrationPoint> $points
     *
     * @return array{left_out: string|null, ids: list<string>, degree: float, passed: bool}
     */
    private static function set(?string $leftOut, array $points, float $threshold): array
    {
        $degree = self::degree($points);

        return [
            'left_out' => $leftOut,
            'ids' => array_map(static fn (CalibrationPoint $p): string => $p->id, $points),
            'degree' => $degree,
            'passed' => $degree >= $threshold,
        ];
    }
}
