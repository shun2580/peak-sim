<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Predict;

use PeakSim\Runtime\Simulate\Calibrate\CalibrationResult;

/**
 * The prediction of one target scenario (simulator-spec.md sections 6.1, 6.3, 6.4).
 *
 * No randomness and no clock: the same scenario and calibration give the same array. This class never reads `observed`.
 *
 * Utilisation (L / cores):
 *  - point   the point estimate (H1) with the calibration's point value
 *  - upper   the same, with a marked route's rows replaced by its upper side
 *  - range   the smallest and largest value over every reading of the estimate (point, upper, low, high) and every fit of the calibration's
 *            width (CalibrationWidth: the leave-one-out fits, never the one-coefficient fits beside a two-coefficient fit)
 *  - width   the ratios upper end / point and point / lower end, and what each source alone widens: the estimate alone (point calibration,
 *            every reading) and the calibration alone (point reading, every fit of the width)
 * Limit multiplier: point and safe (the upper side), each by LimitSearch on the whole-number steps.
 */
final class ScenarioPredictor
{
    /**
     * @param array<string, mixed>                                                                     $scenario a target scenario of the loaded input
     * @param (callable(array<string, int>, array<string, float>): list<array<string, mixed>>)|null     $evaluate re-evaluates the routes at other table counts (for the sensitivity)
     * @param CalibrationWidth|null                                                                    $width    the width of the calibration; when null, what the result alone gives (CalibrationWidth::ofResult)
     *
     * @return array<string, mixed>
     */
    public static function predict(array $scenario, CalibrationResult $calibration, float $cores, float $threshold, ?callable $evaluate = null, ?CalibrationWidth $width = null): array
    {
        if ($cores <= 0.0) {
            throw new \InvalidArgumentException('cores must be above 0');
        }
        /** @var list<array<string, mixed>> $routes */
        $routes = array_values((array) ($scenario['routes'] ?? []));
        $coefficients = Coefficients::pointOf($calibration);

        $width ??= CalibrationWidth::ofResult($calibration);

        $utilisation = [];
        $min = null;
        $max = null;
        $estimateMin = null;
        $estimateMax = null;
        $calibrationMin = null;
        $calibrationMax = null;
        foreach (UtilizationModel::READINGS as $reading) {
            $rates = UtilizationModel::rates($routes, $reading);
            $pointLoad = $calibration->predict($rates['queries'], $rates['rows'])['point'] / $cores;
            $utilisation[$reading] = $pointLoad;
            // the loads of this reading: the point calibration, and every fit of the calibration's width
            $loads = [$pointLoad];
            foreach ($width->loads($rates['queries'], $rates['rows']) as $l) {
                $loads[] = $l / $cores;
                if ($reading === UtilizationModel::POINT) {
                    $calibrationMin = $calibrationMin === null ? $l / $cores : min($calibrationMin, $l / $cores);
                    $calibrationMax = $calibrationMax === null ? $l / $cores : max($calibrationMax, $l / $cores);
                }
            }
            $estimateMin = $estimateMin === null ? $pointLoad : min($estimateMin, $pointLoad);
            $estimateMax = $estimateMax === null ? $pointLoad : max($estimateMax, $pointLoad);
            $min = $min === null ? min($loads) : min($min, ...$loads);
            $max = $max === null ? max($loads) : max($max, ...$loads);
        }
        $point = $utilisation[UtilizationModel::POINT];
        $upper = $utilisation[UtilizationModel::UPPER];

        $coverage = Coverage::of($routes);
        $givesBounds = false;
        foreach ($routes as $r) {
            if (isset($r['rows_low']) || isset($r['rows_high']) || isset($r['queries_low']) || isset($r['queries_high'])) {
                $givesBounds = true;
            }
        }
        $warnings = [];
        if ($coverage['low_coverage']) {
            $warnings[] = sprintf(
                'low_coverage: routes with no cost carry %.1f%% of the rps (more than %d%%); they add nothing to the load, and the prediction is a floor of what the listed routes need',
                100.0 * $coverage['unknown_cost_rps_share'],
                (int) (100 * Coverage::LOW_COVERAGE_OVER),
            );
        }
        $unknownTables = array_values(array_map('strval', (array) ($scenario['unknown_tables'] ?? [])));
        if ($unknownTables !== []) {
            $warnings[] = 'tables with no count (their routes have no cost): ' . implode(', ', $unknownTables);
        }

        $limitPoint = LimitSearch::findProportional($point, $threshold);
        $limitSafe = LimitSearch::findProportional($upper, $threshold);
        $perRoute = UtilizationModel::perRoute($routes, $coefficients, UtilizationModel::POINT);
        $total = array_sum($perRoute);

        $tables = [];
        foreach ((array) ($scenario['tables'] ?? []) as $name => $count) {
            $tables[(string) $name] = (int) $count;
        }
        $marks = ['measured' => 0, 'interpolated' => 0, 'extrapolated' => 0, 'unknown' => 0];
        $routeRows = [];
        foreach ($routes as $r) {
            $mark = $r['n_mark'] ?? null;
            $marks[is_string($mark) && isset($marks[$mark]) ? $mark : 'unknown']++;
            $name = (string) $r['route'];
            $routeRows[] = [
                'route' => $name,
                'rps' => (float) $r['rps'],
                'cost' => (string) $r['cost'],
                'unknown_reason' => $r['unknown_reason'] ?? null,
                'table' => $r['table'] ?? null,
                'n' => $r['n'] ?? null,
                'n_mark' => $mark,
                'marked' => ($r['marked'] ?? false) === true,
                'queries' => $r['queries'] ?? null,
                'rows' => $r['rows'] ?? null,
                'rows_upper' => $r['rows_upper'] ?? null,
                'sources' => $r['sources'] ?? null,
                'load' => $perRoute[$name] ?? null,
                'load_share' => isset($perRoute[$name]) && $total > 0.0 ? $perRoute[$name] / $total : null,
            ];
        }

        return [
            'id' => (string) $scenario['id'],
            'tables' => $tables,
            'rps_total' => $coverage['rps_total'],
            'utilization' => [
                'point' => $point,
                'upper' => $upper,
                'range' => ['min' => $min, 'max' => $max],
                'range_basis' => [
                    'estimate' => $givesBounds ? ['point', 'upper', 'low', 'high'] : ['point', 'upper'],
                    'calibration_width' => $width->status,
                    'calibration_fits' => $width->isAvailable() ? $width->labels() : [self::pointFitName($calibration)],
                ],
                'width' => [
                    'upper_over_point' => $point > 0.0 ? $max / $point : null,
                    'point_over_lower' => $min > 0.0 ? $point / $min : null,
                    'estimate_only' => ['min' => $estimateMin, 'max' => $estimateMax],
                    'calibration_only' => $width->isAvailable() ? ['min' => $calibrationMin, 'max' => $calibrationMax] : null,
                    'calibration_width_note' => $width->isAvailable() ? null : $width->reason,
                ],
                'threshold' => $threshold,
                'over_threshold' => ['point' => LimitSearch::exceeds($point, $threshold), 'upper' => LimitSearch::exceeds($upper, $threshold)],
                'by_reading' => $utilisation,
            ],
            'limit_multiplier' => [
                'point' => $limitPoint,
                'safe' => $limitSafe,
                'step' => 1 / LimitSearch::PER_UNIT,
                'cap' => (float) (LimitSearch::STEPS / LimitSearch::PER_UNIT),
            ],
            'dominant_routes' => DominantRoutes::top($routes, $coefficients),
            'coverage' => $coverage,
            'low_coverage' => $coverage['low_coverage'],
            'zero_rps_routes' => array_values(array_map('strval', (array) ($scenario['zero_rps_routes'] ?? []))),
            'unknown_tables' => $unknownTables,
            'n_marks' => $marks,
            'sensitivity' => Sensitivity::top($tables, $routes, $coefficients, $cores, $threshold, $evaluate),
            'routes' => $routeRows,
            'warnings' => $warnings,
        ];
    }

    private static function pointFitName(CalibrationResult $calibration): string
    {
        return $calibration->method === CalibrationResult::METHOD_TWO ? 'two_coefficient' : 'mean_of_one_coefficient_fits';
    }
}
