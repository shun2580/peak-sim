<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Simulate\Predict;

use PeakSim\Runtime\Simulate\Calibrate\CalibrationFit;
use PeakSim\Runtime\Simulate\Calibrate\CalibrationResult;

/** Small hand-made inputs for the prediction tests. The expected values in the tests are worked out by hand from these numbers. */
final class PredictFixtures
{
    /** A route as the loaded input gives it. */
    public static function route(string $name, float $rps, float $queries, float $rows, ?float $upper = null, array $extra = []): array
    {
        return $extra + [
            'route' => $name,
            'rps' => $rps,
            'table' => 'items',
            'n' => 1000,
            'cost' => 'ok',
            'unknown_reason' => null,
            'queries' => $queries,
            'rows' => $rows,
            'rows_upper' => $upper,
            'marked' => $upper !== null,
            'peak_memory' => null,
            'peak_memory_status' => 'not_given',
            'n_mark' => 'extrapolated',
            'sources' => ['queries' => 'fit', 'rows' => 'fit', 'rows_upper' => $upper === null ? null : 'explain', 'peak_memory' => null],
            'types' => [],
        ];
    }

    /** A route whose cost is not known. */
    public static function unknown(string $name, float $rps): array
    {
        return [
            'route' => $name,
            'rps' => $rps,
            'table' => null,
            'n' => null,
            'cost' => 'unknown',
            'unknown_reason' => 'no_estimate',
            'queries' => null,
            'rows' => null,
            'rows_upper' => null,
            'marked' => false,
            'peak_memory' => null,
            'peak_memory_status' => 'unknown',
            'n_mark' => null,
            'sources' => ['queries' => null, 'rows' => null, 'rows_upper' => null, 'peak_memory' => null],
            'types' => [],
        ];
    }

    /** A statement type of a route. */
    public static function type(string $id, float $count, float $rows, bool $unstable = false, bool $changes = false): array
    {
        return [
            'id' => $id,
            'kind' => 'select',
            'pattern' => 'select * from t_' . $id,
            'count' => $count,
            'per_exec' => $count > 0.0 ? $rows / $count : 0.0,
            'per_exec_upper' => null,
            'marks' => ['plan_changes_at_scale' => $changes, 'plan_unstable' => $unstable],
            'rows' => $rows,
            'rows_upper' => $rows,
        ];
    }

    /** A two-coefficient calibration with the one-coefficient fits beside it. */
    public static function twoCoefficient(float $cQuery, float $cRow, float $queriesOnly = 0.0, float $rowsOnly = 0.0): CalibrationResult
    {
        $fits = [new CalibrationFit(CalibrationFit::TWO, $cQuery, $cRow, [], [], [], [], 0.0)];
        if ($queriesOnly > 0.0) {
            $fits[] = new CalibrationFit(CalibrationFit::QUERIES_ONLY, $queriesOnly, 0.0, [], ['c_row'], [], [], 0.0);
        }
        if ($rowsOnly > 0.0) {
            $fits[] = new CalibrationFit(CalibrationFit::ROWS_ONLY, 0.0, $rowsOnly, [], ['c_query'], [], [], 0.0);
        }

        return new CalibrationResult(CalibrationResult::METHOD_TWO, 2, $fits, [], 0);
    }

    /** A one-scenario calibration: the queries-only and the rows-only fits. */
    public static function oneCoefficient(float $queriesOnly, float $rowsOnly): CalibrationResult
    {
        return new CalibrationResult(
            CalibrationResult::METHOD_ONE,
            1,
            [
                new CalibrationFit(CalibrationFit::QUERIES_ONLY, $queriesOnly, 0.0, [], ['c_row'], [], [], 0.0),
                new CalibrationFit(CalibrationFit::ROWS_ONLY, 0.0, $rowsOnly, [], ['c_query'], [], [], 0.0),
            ],
            [],
            0,
        );
    }
}
