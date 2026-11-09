<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Predict;

use PeakSim\Runtime\Simulate\Calibrate\CalibrationResult;

/**
 * The whole output of a run (simulator-spec.md section 6.4) as one array, in a fixed key order: the same input gives the same JSON.
 * It holds no clock reading and nothing of a target's `observed`.
 */
final class PredictionReport
{
    public const SCHEMA_VERSION = '0.1';

    /**
     * @param array<string, mixed>                                                                      $loaded     LoadedInput::toArray()
     * @param array<string, array{table: string|null, a: float, b: float, k: float}|null>              $memory     route => peak-memory formula (null: none)
     * @param array<string, mixed>                                                                      $accuracy   AccuracyRecord::load()
     * @param (callable(array<string, int>, array<string, float>): list<array<string, mixed>>)|null      $evaluate
     *
     * @return array<string, mixed>
     */
    public static function build(array $loaded, CalibrationResult $calibration, array $memory, MemoryAccuracy $memoryAccuracy, array $accuracy, ?callable $evaluate = null): array
    {
        $resources = (array) $loaded['resources'];
        $cores = (float) $resources['cores'];
        $threshold = (float) $resources['threshold'];
        $limitBytes = isset($resources['php_memory_limit_bytes']) ? (int) $resources['php_memory_limit_bytes'] : null;

        $width = CalibrationWidth::of(PredictStage::points($loaded), $calibration);
        $scenarios = [];
        foreach ((array) $loaded['scenarios'] as $s) {
            if (($s['kind'] ?? null) === 'target') {
                $scenarios[] = ScenarioPredictor::predict($s, $calibration, $cores, $threshold, $evaluate, $width);
            }
        }
        $lowCoverage = array_values(array_map(static fn (array $p): string => $p['id'], array_filter($scenarios, static fn (array $p): bool => $p['low_coverage'])));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'app' => $loaded['app'] ?? null,
            'estimate_at' => $loaded['estimate_at'] ?? null,
            'resources' => ['cores' => $cores, 'threshold' => $threshold, 'php_memory_limit_bytes' => $limitBytes],
            'low_coverage_scenarios' => $lowCoverage,
            'calibration' => $calibration->toArray() + ['width' => $width->toArray()],
            'scenarios' => $scenarios,
            'peak_memory' => [
                'line_share_of_limit' => PeakMemoryLimit::SHARE,
                'accuracy_source' => $memoryAccuracy->source,
                'routes' => PeakMemoryLimit::all($memory, $memoryAccuracy, $limitBytes),
            ],
            'accuracy_on_this_app' => $accuracy,
            'sources' => [
                'static' => 'the static analysis fixed the value',
                'explain' => 'the EXPLAIN estimate gave the value (the upper side of a marked statement)',
                'fit' => 'a formula fitted to measurements at small sizes',
                'unknown' => 'no cost (listed apart, never counted as 0)',
            ],
            'n_marks' => [
                'measured' => 'the count is one of the sizes measured',
                'interpolated' => 'the count is between measured sizes',
                'extrapolated' => 'the count is outside the measured sizes',
            ],
        ];
    }
}
