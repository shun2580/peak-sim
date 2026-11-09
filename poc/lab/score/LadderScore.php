<?php

declare(strict_types=1);

namespace PeakSim\Lab\Score;

/**
 * Scores the lab backtest (simulator-spec.md sections 7.2 and 7.4 B) from the ladder of each target scenario. A pure function of its
 * input: no file, no clock, no randomness. It copies the rules of the spec; nothing here moves a line.
 *
 * Input (utilisations are fractions, 0.21 = 21%; rps are requests per second):
 *
 *   ['threshold' => 0.8,
 *    'scenarios' => [[
 *        'id' => 't-1',
 *        'stages' => [[
 *            'rps' => 0.05,
 *            'predicted' => ['point' => 0.10, 'safe' => 0.12, 'naive' => 0.20],
 *            'measured' => null                                   // the stage was not measured (the scenario stopped before it)
 *                        | ['valid' => true, 'utilization' => 0.11],   // valid: the decided rps came out (within 5%)
 *        ], ...],
 *        'predicted_limit_rps' => ['point' => 1.2, 'safe' => 1.0, 'naive' => 2.0],   // each may be null
 *    ], ...]]
 *
 * The rules, as written in the spec:
 *  - a stage is USABLE for the utilisation difference when it is valid and its observed utilisation is 10% to 90% (both ends in)
 *  - item 1: the mean |prediction - observed| (point) over the usable stages is smaller than that of the naive estimate
 *  - item 2: that mean is within 15 points (target 5)
 *  - item 3: over ALL valid stages, the share whose "exceeded the threshold" (utilisation > threshold) agrees with the observation is 80% or more
 *  - item 4: over the scenarios whose limit rps was measured, the share whose predicted limit (point) is within 20% (target 10%) of the measured one
 *    is 80% or more
 *  - the measured limit rps: a straight line between the two valid stages that straddle the threshold (the first valid stage that reaches it,
 *    and the valid stage just below that); if no valid stage reaches it, a line from the two valid stages with the largest rps, extended, and
 *    marked so; fewer than two valid stages: not measured
 *  - INDETERMINATE: fewer than 10 usable stages, or fewer than 4 scenarios whose limit rps was measured. Never counted as a pass
 * Section 7.4 B also has the naive estimate stand beside items 1 and 4 (item 4 against the naive estimate is not used for the verdict).
 */
final class LadderScore
{
    public const SCHEMA_VERSION = '0.1';

    /** Usable stages: observed utilisation from 10% to 90%. */
    public const USABLE_MIN = 0.10;

    public const USABLE_MAX = 0.90;

    /** Item 2: the mean absolute difference, in utilisation points. */
    public const MEAN_DIFFERENCE_MINIMUM_POINTS = 15.0;

    public const MEAN_DIFFERENCE_TARGET_POINTS = 5.0;

    /** Item 4: the relative error of the predicted limit rps. */
    public const LIMIT_TOLERANCE_MINIMUM = 0.20;

    public const LIMIT_TOLERANCE_TARGET = 0.10;

    /** Items 3 and 4: 80% or more, kept as 8 in 10 so that no floating point decides it. */
    public const SHARE_NUMERATOR = 8;

    public const SHARE_DENOMINATOR = 10;

    /** Indeterminate below these. */
    public const MIN_USABLE_STAGES = 10;

    public const MIN_SCENARIOS_WITH_LIMIT = 4;

    /** A value within this of a line counts as on the line (floating-point noise must not decide a pass). */
    private const EPSILON = 1e-9;

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException when the input is not in the form above
     */
    public static function score(array $input): array
    {
        $threshold = self::number($input['threshold'] ?? null, 'threshold');
        if ($threshold <= 0.0 || $threshold >= 1.0) {
            throw new \InvalidArgumentException('the threshold must be above 0 and below 1');
        }
        if (!isset($input['scenarios']) || !is_array($input['scenarios']) || $input['scenarios'] === []) {
            throw new \InvalidArgumentException('there is no scenario');
        }

        $scenarios = [];
        $seen = [];
        $stagesMeasured = 0;
        $validStages = 0;
        $usableStages = 0;
        $diffPoint = [];
        $diffNaive = [];
        $agreePoint = 0;
        $agreeNaive = 0;
        $violations = [];
        $limitCount = 0;
        $limitCases = [];

        foreach ($input['scenarios'] as $raw) {
            $scenario = self::scenario($raw, $threshold);
            if (isset($seen[$scenario['id']])) {
                throw new \InvalidArgumentException('the scenario "' . $scenario['id'] . '" appears twice');
            }
            $seen[$scenario['id']] = true;

            foreach ($scenario['stages'] as $stage) {
                if ($stage['observed'] === null) {
                    continue;
                }
                $stagesMeasured++;
                if ($stage['safe_below_observed']) {
                    $violations[] = [
                        'scenario' => $scenario['id'],
                        'rps' => $stage['rps'],
                        'safe' => $stage['predicted']['safe'],
                        'observed' => $stage['observed'],
                        'valid' => $stage['valid'],
                        'usable' => $stage['usable'],
                    ];
                }
                if (!$stage['valid']) {
                    continue;
                }
                $validStages++;
                $agreePoint += $stage['agree']['point'] ? 1 : 0;
                $agreeNaive += $stage['agree']['naive'] ? 1 : 0;
                if ($stage['usable']) {
                    $usableStages++;
                    $diffPoint[] = abs($stage['difference_points']['point']);
                    $diffNaive[] = abs($stage['difference_points']['naive']);
                }
            }

            if ($scenario['limit']['measured']['status'] === 'measured') {
                $limitCount++;
                $limitCases[] = $scenario['limit'];
            }
            $scenarios[] = $scenario;
        }

        $meanPoint = $diffPoint === [] ? null : array_sum($diffPoint) / count($diffPoint);
        $meanNaive = $diffNaive === [] ? null : array_sum($diffNaive) / count($diffNaive);

        $reasons = [];
        if ($usableStages < self::MIN_USABLE_STAGES) {
            $reasons[] = 'usable stages ' . $usableStages . ' < ' . self::MIN_USABLE_STAGES;
        }
        if ($limitCount < self::MIN_SCENARIOS_WITH_LIMIT) {
            $reasons[] = 'scenarios whose limit rps was measured ' . $limitCount . ' < ' . self::MIN_SCENARIOS_WITH_LIMIT;
        }
        $indeterminate = $reasons !== [];

        $item1 = [
            'name' => 'mean absolute difference of the prediction is smaller than that of the naive estimate',
            'mean_points' => $meanPoint,
            'mean_points_naive' => $meanNaive,
            'minimum' => $meanPoint !== null && $meanPoint < $meanNaive - self::EPSILON,
            'target' => $meanPoint !== null && $meanPoint < $meanNaive - self::EPSILON,
        ];
        $item2 = [
            'name' => 'mean absolute difference (points)',
            'mean_points' => $meanPoint,
            'line_minimum_points' => self::MEAN_DIFFERENCE_MINIMUM_POINTS,
            'line_target_points' => self::MEAN_DIFFERENCE_TARGET_POINTS,
            'minimum' => $meanPoint !== null && $meanPoint <= self::MEAN_DIFFERENCE_MINIMUM_POINTS + self::EPSILON,
            'target' => $meanPoint !== null && $meanPoint <= self::MEAN_DIFFERENCE_TARGET_POINTS + self::EPSILON,
        ];
        $item3Met = self::shareMet($agreePoint, $validStages);
        $item3 = [
            'name' => 'agreement on "exceeded the threshold" over all valid stages',
            'agree' => $agreePoint,
            'valid_stages' => $validStages,
            'share' => $validStages === 0 ? null : $agreePoint / $validStages,
            'share_naive' => $validStages === 0 ? null : $agreeNaive / $validStages,
            'minimum' => $item3Met,
            'target' => $item3Met,
        ];
        $within = static function (string $key, float $tolerance) use ($limitCases): int {
            $n = 0;
            foreach ($limitCases as $case) {
                if ($case['within'][$key][(string) $tolerance] ?? false) {
                    $n++;
                }
            }

            return $n;
        };
        $item4 = [
            'name' => 'predicted limit rps (point) within the tolerance of the measured one, over scenarios whose limit was measured',
            'scenarios_with_limit' => $limitCount,
            'within_minimum' => $within('point', self::LIMIT_TOLERANCE_MINIMUM),
            'within_target' => $within('point', self::LIMIT_TOLERANCE_TARGET),
            'tolerance_minimum' => self::LIMIT_TOLERANCE_MINIMUM,
            'tolerance_target' => self::LIMIT_TOLERANCE_TARGET,
            'minimum' => self::shareMet($within('point', self::LIMIT_TOLERANCE_MINIMUM), $limitCount),
            'target' => self::shareMet($within('point', self::LIMIT_TOLERANCE_TARGET), $limitCount),
            'safe_within_minimum' => $within('safe', self::LIMIT_TOLERANCE_MINIMUM),
            'safe_within_target' => $within('safe', self::LIMIT_TOLERANCE_TARGET),
            'naive_within_minimum' => $within('naive', self::LIMIT_TOLERANCE_MINIMUM),
            'naive_within_target' => $within('naive', self::LIMIT_TOLERANCE_TARGET),
            'naive_used_for_the_verdict' => false,
        ];

        $allMinimum = $item1['minimum'] && $item2['minimum'] && $item3['minimum'] && $item4['minimum'];
        $allTarget = $item1['target'] && $item2['target'] && $item3['target'] && $item4['target'];
        if ($indeterminate) {
            $verdict = 'indeterminate';
        } elseif ($allTarget) {
            $verdict = 'pass_target';
        } elseif ($allMinimum) {
            $verdict = 'pass_minimum';
        } else {
            $verdict = 'fail';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'threshold' => $threshold,
            'usable_range' => [self::USABLE_MIN, self::USABLE_MAX],
            'counts' => [
                'scenarios' => count($scenarios),
                'stages_measured' => $stagesMeasured,
                'valid_stages' => $validStages,
                'usable_stages' => $usableStages,
                'scenarios_with_limit' => $limitCount,
            ],
            'indeterminate' => ['is' => $indeterminate, 'reasons' => $reasons],
            'items' => ['1' => $item1, '2' => $item2, '3' => $item3, '4' => $item4],
            'verdict' => $verdict,
            'scenarios' => $scenarios,
            'safe_side_below_observed' => $violations,
        ];
    }

    /** 80% or more, in whole numbers. An empty denominator is never a pass. */
    private static function shareMet(int $count, int $of): bool
    {
        return $of > 0 && $count * self::SHARE_DENOMINATOR >= $of * self::SHARE_NUMERATOR;
    }

    /**
     * @return array<string, mixed>
     */
    private static function scenario(mixed $raw, float $threshold): array
    {
        if (!is_array($raw) || !isset($raw['id']) || !is_string($raw['id']) || $raw['id'] === '') {
            throw new \InvalidArgumentException('a scenario has no id');
        }
        $id = $raw['id'];
        if (!isset($raw['stages']) || !is_array($raw['stages'])) {
            throw new \InvalidArgumentException('the scenario "' . $id . '" has no stages');
        }

        $stages = [];
        $rpsSeen = [];
        foreach ($raw['stages'] as $s) {
            $stage = self::stage($s, $id, $threshold);
            $key = sprintf('%.12g', $stage['rps']);
            if (isset($rpsSeen[$key])) {
                throw new \InvalidArgumentException('the scenario "' . $id . '" has the rps ' . $key . ' twice');
            }
            $rpsSeen[$key] = true;
            $stages[] = $stage;
        }
        usort($stages, static fn (array $a, array $b): int => $a['rps'] <=> $b['rps']);

        $measuredLimit = self::measuredLimit($stages, $threshold);
        $predictedLimit = self::predictedLimit($raw['predicted_limit_rps'] ?? null, $id);

        $within = ['point' => [], 'safe' => [], 'naive' => []];
        $relative = ['point' => null, 'safe' => null, 'naive' => null];
        if ($measuredLimit['status'] === 'measured') {
            foreach (['point', 'safe', 'naive'] as $kind) {
                $p = $predictedLimit[$kind];
                if ($p !== null) {
                    $relative[$kind] = ($p - $measuredLimit['rps']) / $measuredLimit['rps'];
                }
                foreach ([self::LIMIT_TOLERANCE_MINIMUM, self::LIMIT_TOLERANCE_TARGET] as $tolerance) {
                    $within[$kind][(string) $tolerance] = $p !== null
                        && abs($p - $measuredLimit['rps']) <= $tolerance * $measuredLimit['rps'] * (1.0 + self::EPSILON);
                }
            }
        }

        return [
            'id' => $id,
            'stages' => $stages,
            'limit' => [
                'measured' => $measuredLimit,
                'predicted' => $predictedLimit,
                'relative_error' => $relative,
                'within' => $within,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function stage(mixed $raw, string $id, float $threshold): array
    {
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('a stage of "' . $id . '" is not an object');
        }
        $rps = self::number($raw['rps'] ?? null, 'rps of a stage of "' . $id . '"');
        if ($rps <= 0.0) {
            throw new \InvalidArgumentException('the rps of a stage of "' . $id . '" is not above 0');
        }
        $p = $raw['predicted'] ?? null;
        if (!is_array($p)) {
            throw new \InvalidArgumentException('a stage of "' . $id . '" has no prediction');
        }
        $predicted = [];
        foreach (['point', 'safe', 'naive'] as $kind) {
            $predicted[$kind] = self::utilization($p[$kind] ?? null, 'the ' . $kind . ' prediction at rps ' . $rps . ' of "' . $id . '"');
        }

        $m = $raw['measured'] ?? null;
        $observed = null;
        $valid = false;
        if ($m !== null) {
            if (!is_array($m) || !array_key_exists('valid', $m) || !is_bool($m['valid'])) {
                throw new \InvalidArgumentException('the measurement at rps ' . $rps . ' of "' . $id . '" has no true/false "valid"');
            }
            $valid = $m['valid'];
            $observed = self::utilization($m['utilization'] ?? null, 'the observed utilisation at rps ' . $rps . ' of "' . $id . '"');
        }

        $usable = $observed !== null && $valid
            && $observed >= self::USABLE_MIN - self::EPSILON && $observed <= self::USABLE_MAX + self::EPSILON;
        $difference = ['point' => null, 'safe' => null, 'naive' => null];
        $over = ['point' => null, 'safe' => null, 'naive' => null, 'observed' => null];
        $agree = ['point' => null, 'naive' => null];
        $safeBelow = false;
        if ($observed !== null) {
            foreach (['point', 'safe', 'naive'] as $kind) {
                $difference[$kind] = ($predicted[$kind] - $observed) * 100.0;
                $over[$kind] = self::exceeds($predicted[$kind], $threshold);
            }
            $over['observed'] = self::exceeds($observed, $threshold);
            $agree['point'] = $over['point'] === $over['observed'];
            $agree['naive'] = $over['naive'] === $over['observed'];
            $safeBelow = $predicted['safe'] < $observed - self::EPSILON;
        }

        return [
            'rps' => $rps,
            'predicted' => $predicted,
            'observed' => $observed,
            'valid' => $valid,
            'usable' => $usable,
            'difference_points' => $difference,
            'over_threshold' => $over,
            'agree' => $agree,
            'safe_below_observed' => $safeBelow,
        ];
    }

    /** "Exceeded the threshold": strictly above it (a utilisation of exactly the threshold has not exceeded it). */
    private static function exceeds(float $utilization, float $threshold): bool
    {
        return $utilization > $threshold + self::EPSILON;
    }

    /** "Reached the threshold": at or above it. */
    private static function reaches(float $utilization, float $threshold): bool
    {
        return $utilization >= $threshold - self::EPSILON;
    }

    /**
     * @param list<array<string, mixed>> $stages sorted by rps, ascending
     *
     * @return array<string, mixed>
     */
    private static function measuredLimit(array $stages, float $threshold): array
    {
        $valid = [];
        foreach ($stages as $stage) {
            if ($stage['observed'] !== null && $stage['valid']) {
                $valid[] = ['rps' => $stage['rps'], 'utilization' => $stage['observed']];
            }
        }
        if (count($valid) < 2) {
            return ['status' => 'not_measured', 'reason' => 'fewer than two valid stages (' . count($valid) . ')', 'method' => null, 'rps' => null, 'basis' => $valid];
        }

        $first = null;
        foreach ($valid as $i => $v) {
            if (self::reaches($v['utilization'], $threshold)) {
                $first = $i;
                break;
            }
        }

        if ($first !== null && $first > 0) {
            $lo = $valid[$first - 1];
            $hi = $valid[$first];
            $rps = $lo['rps'] + ($threshold - $lo['utilization']) / ($hi['utilization'] - $lo['utilization']) * ($hi['rps'] - $lo['rps']);

            return ['status' => 'measured', 'reason' => null, 'method' => 'interpolated', 'rps' => $rps, 'basis' => [$lo, $hi]];
        }

        // No valid stage below the threshold sits under one that reaches it, or no valid stage reaches it: a line is extended.
        // The two valid stages nearest the threshold on the side they lie are used: the two with the largest rps when all lie below it
        // (the spec), the two with the smallest rps when all lie at or above it (the spec does not say; the same idea).
        $pair = $first === null ? [$valid[count($valid) - 2], $valid[count($valid) - 1]] : [$valid[0], $valid[1]];
        [$a, $b] = $pair;
        $slope = ($b['utilization'] - $a['utilization']) / ($b['rps'] - $a['rps']);
        if ($slope <= 0.0) {
            return ['status' => 'not_measured', 'reason' => 'the line through the two nearest valid stages does not rise, so it never reaches the threshold', 'method' => null, 'rps' => null, 'basis' => $pair];
        }
        $anchor = $first === null ? $b : $a;
        $rps = $anchor['rps'] + ($threshold - $anchor['utilization']) / $slope;
        if ($rps <= 0.0) {
            return ['status' => 'not_measured', 'reason' => 'the extended line reaches the threshold at an rps of 0 or less', 'method' => null, 'rps' => null, 'basis' => $pair];
        }

        return ['status' => 'measured', 'reason' => null, 'method' => 'extended', 'rps' => $rps, 'basis' => $pair];
    }

    /**
     * @return array{point: float|null, safe: float|null, naive: float|null}
     */
    private static function predictedLimit(mixed $raw, string $id): array
    {
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('the scenario "' . $id . '" has no predicted limit rps (each of point, safe and naive may be null)');
        }
        $out = [];
        foreach (['point', 'safe', 'naive'] as $kind) {
            $v = $raw[$kind] ?? null;
            if ($v === null) {
                $out[$kind] = null;
                continue;
            }
            $v = self::number($v, 'the ' . $kind . ' predicted limit rps of "' . $id . '"');
            if ($v <= 0.0) {
                throw new \InvalidArgumentException('the ' . $kind . ' predicted limit rps of "' . $id . '" is not above 0 (use null for "no limit")');
            }
            $out[$kind] = $v;
        }

        return $out;
    }

    private static function utilization(mixed $v, string $what): float
    {
        $n = self::number($v, $what);
        if ($n < 0.0) {
            throw new \InvalidArgumentException($what . ' is negative');
        }

        return $n;
    }

    private static function number(mixed $v, string $what): float
    {
        if (!is_int($v) && !is_float($v) || !is_finite((float) $v)) {
            throw new \InvalidArgumentException($what . ' is not a number');
        }

        return (float) $v;
    }
}
