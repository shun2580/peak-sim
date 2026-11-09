<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Backtest;

/**
 * What the backtest says once the prediction is on record and the observed utilisation of the target scenarios has been read
 * (simulator-spec.md sections 7.3, 7.4 B and 8.2). A pure function of three inputs: no file, no clock, no randomness.
 *
 * Per target scenario that has an observed value:
 *  - the prediction (point and upper side), the observed value and the difference (prediction - observed, in utilisation points)
 *  - the naive estimate of section 7.3 and its difference
 *  - whether the prediction, and the naive estimate, agree with the observation on "over the threshold"
 *
 * Over the scenarios that have an observed value, the three items of section 7.4 B with the minimum line and the target:
 *  1. the mean absolute difference of the prediction is smaller than that of the naive estimate
 *  2. the mean absolute difference is within 15 points (target 10)
 *  3. the share of scenarios whose "over the threshold" agrees with the observation is 80% or more (not used when no scenario
 *     is over the threshold in the observation)
 *
 * The lines are those of the spec; nothing here moves them. A target scenario with no observed value is listed apart and is not counted.
 */
final class BacktestReport
{
    public const SCHEMA_VERSION = '0.1';

    /** Section 7.4 B, item 2: the mean absolute difference, in utilisation points (1 point = 0.01). */
    public const MEAN_DIFFERENCE_MINIMUM_POINTS = 15.0;

    public const MEAN_DIFFERENCE_TARGET_POINTS = 10.0;

    /** Section 7.4 B, item 3: the share of scenarios whose "over the threshold" agrees. */
    public const AGREEMENT_SHARE_LINE = 0.8;

    /** A value within this of a line counts as on the line (floating-point noise must not decide a pass). */
    private const EPSILON = 1e-9;

    /**
     * @param array<string, mixed>  $loaded     LoadedInput::toArray()
     * @param array<string, mixed>  $prediction the report of PredictionReport::build()
     * @param array<string, float>  $observed   target scenario id => observed db_cpu_utilization (TwoStage::stageTwo)
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException when the naive estimate cannot be made (no calibration scenario, or its sum of rps is 0)
     */
    public static function build(array $loaded, array $prediction, array $observed): array
    {
        $calibrations = [];
        foreach ((array) $loaded['scenarios'] as $s) {
            if (($s['kind'] ?? null) === 'calibration') {
                $calibrations[] = [
                    'id' => (string) $s['id'],
                    'rps_total' => NaiveEstimate::rpsTotal(self::rpsOf($s['routes'])),
                    'utilization' => (float) $s['observed']['db_cpu_utilization'],
                ];
            }
        }
        $threshold = (float) $prediction['resources']['threshold'];

        $rows = [];
        $withoutObserved = [];
        foreach ((array) $prediction['scenarios'] as $p) {
            $id = (string) $p['id'];
            if (!array_key_exists($id, $observed)) {
                $withoutObserved[] = $id;
                continue;
            }
            $naive = NaiveEstimate::estimate($calibrations, (float) $p['rps_total']);
            $actual = (float) $observed[$id];
            $point = (float) $p['utilization']['point'];
            $upper = (float) $p['utilization']['upper'];
            $rows[] = [
                'id' => $id,
                'observed' => $actual,
                'predicted' => [
                    'point' => $point,
                    'upper' => $upper,
                    'difference' => $point - $actual,
                    'absolute_difference' => abs($point - $actual),
                    'over_threshold' => $point > $threshold,
                ],
                'naive' => [
                    'utilization' => $naive['utilization'],
                    'difference' => $naive['utilization'] - $actual,
                    'absolute_difference' => abs($naive['utilization'] - $actual),
                    'over_threshold' => $naive['utilization'] > $threshold,
                    'basis_scenario' => $naive['basis_id'],
                    'basis_utilization' => $naive['basis_utilization'],
                    'basis_rps_total' => $naive['basis_rps_total'],
                    'target_rps_total' => $naive['target_rps_total'],
                ],
                'observed_over_threshold' => $actual > $threshold,
                'prediction_agrees_on_threshold' => ($point > $threshold) === ($actual > $threshold),
                'naive_agrees_on_threshold' => ($naive['utilization'] > $threshold) === ($actual > $threshold),
                'low_coverage' => ($p['low_coverage'] ?? false) === true,
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'app' => $prediction['app'] ?? null,
            'threshold' => $threshold,
            'scenarios' => $rows,
            'target_scenarios_without_observed' => $withoutObserved,
            'summary' => self::summary($rows),
            'note' => 'Differences are prediction (or naive estimate) minus observed, in utilisation (0.01 = 1 point). The lines are those of simulator-spec.md section 7.4 B and are not moved here.',
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private static function summary(array $rows): array
    {
        $n = count($rows);
        if ($n === 0) {
            return ['scenarios_compared' => 0, 'items' => null];
        }
        $meanOf = static function (string $key) use ($rows, $n): float {
            $sum = 0.0;
            foreach ($rows as $r) {
                $sum += (float) $r[$key]['absolute_difference'];
            }

            return $sum / $n;
        };
        $mean = $meanOf('predicted');
        $meanNaive = $meanOf('naive');
        $agree = 0;
        $agreeNaive = 0;
        $overObserved = 0;
        foreach ($rows as $r) {
            $agree += $r['prediction_agrees_on_threshold'] ? 1 : 0;
            $agreeNaive += $r['naive_agrees_on_threshold'] ? 1 : 0;
            $overObserved += $r['observed_over_threshold'] ? 1 : 0;
        }
        $points = $mean * 100.0;
        $share = $agree / $n;

        return [
            'scenarios_compared' => $n,
            'mean_absolute_difference' => $mean,
            'mean_absolute_difference_naive' => $meanNaive,
            'items' => [
                'beats_naive' => [
                    'value' => $mean < $meanNaive,
                    'minimum' => true,
                    'target' => true,
                ],
                'mean_difference_points' => [
                    'value' => $points,
                    'minimum_line' => self::MEAN_DIFFERENCE_MINIMUM_POINTS,
                    'target_line' => self::MEAN_DIFFERENCE_TARGET_POINTS,
                    'meets_minimum' => $points <= self::MEAN_DIFFERENCE_MINIMUM_POINTS + self::EPSILON,
                    'meets_target' => $points <= self::MEAN_DIFFERENCE_TARGET_POINTS + self::EPSILON,
                ],
                'threshold_agreement' => $overObserved === 0
                    ? ['applies' => false, 'reason' => 'no scenario is over the threshold in the observation', 'share' => $share, 'share_naive' => $agreeNaive / $n]
                    : [
                        'applies' => true,
                        'share' => $share,
                        'share_naive' => $agreeNaive / $n,
                        'line' => self::AGREEMENT_SHARE_LINE,
                        'meets_line' => $share >= self::AGREEMENT_SHARE_LINE - self::EPSILON,
                    ],
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $routes
     *
     * @return array<string, float>
     */
    private static function rpsOf(array $routes): array
    {
        $rps = [];
        foreach ($routes as $r) {
            $rps[(string) $r['route']] = (float) $r['rps'];
        }

        return $rps;
    }
}
