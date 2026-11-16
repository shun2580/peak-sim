<?php

declare(strict_types=1);

namespace PeakSim\Runtime\LabLoad;

/**
 * Did the decided rps actually come out (spec 7.2: within 5%)? Counted over the kept part of the scenario only, the
 * requests that started after the discarded start. A scenario that missed is "invalid" and is recorded as such.
 *
 * Section 7.4.3: the count is of the requests SENT inside the kept window (`judgeStep`), not of those that had finished by the
 * window's end, and a step in which the load tool dropped any arrival is invalid whatever the count. `judge` is the count alone
 * and stays as it was, for the records made before that rule.
 */
final class RpsAchievement
{
    public const TOLERANCE = 0.05;

    public const REASON_OUTSIDE_TOLERANCE = 'rps_outside_5_percent';
    public const REASON_DROPPED = 'load_tool_dropped_arrivals';
    public const COUNTED_SENT = 'sent';
    public const COUNTED_COMPLETED = 'completed';

    /**
     * @return array{target_rps: float, achieved_rps: float, ratio: float, deviation: float, valid: bool, status: string}
     */
    public static function judge(float $targetRps, int $requestsInWindow, int $windowSec): array
    {
        if (!is_finite($targetRps) || $targetRps <= 0.0 || $windowSec <= 0 || $requestsInWindow < 0) {
            throw new \InvalidArgumentException('target rps and window must be positive and the count not negative');
        }
        $achieved = $requestsInWindow / $windowSec;
        $ratio = $achieved / $targetRps;
        $deviation = $ratio - 1.0;
        // A hair of room for the floating point of a deviation that is exactly 5%.
        $valid = abs($deviation) <= self::TOLERANCE + 1e-9;

        return [
            'target_rps' => $targetRps,
            'achieved_rps' => $achieved,
            'ratio' => $ratio,
            'deviation' => $deviation,
            'valid' => $valid,
            'status' => $valid ? 'valid' : 'invalid',
        ];
    }

    /**
     * The judgment of one step under section 7.4.3: the rps within 5% on the count given, and no arrival dropped by the load tool.
     *
     * `$counted` says what the count is: `sent` (requests sent inside the kept window; the rule) or `completed` (requests that had
     * finished; only for a summary written before the load tool counted the sent ones, so that an old record can be read again).
     *
     * @return array{target_rps: float, achieved_rps: float, ratio: float, deviation: float, valid: bool, status: string, counted: string, dropped_iterations: int, invalid_reasons: list<string>}
     */
    public static function judgeStep(float $targetRps, int $requestsCounted, int $windowSec, int $droppedIterations, string $counted = self::COUNTED_SENT): array
    {
        if ($droppedIterations < 0 || !in_array($counted, [self::COUNTED_SENT, self::COUNTED_COMPLETED], true)) {
            throw new \InvalidArgumentException('dropped iterations must not be negative and the count is either sent or completed');
        }
        $base = self::judge($targetRps, $requestsCounted, $windowSec);
        $reasons = [];
        if (!$base['valid']) {
            $reasons[] = self::REASON_OUTSIDE_TOLERANCE;
        }
        if ($droppedIterations > 0) {
            $reasons[] = self::REASON_DROPPED;
        }
        $valid = $reasons === [];

        return array_merge($base, [
            'valid' => $valid,
            'status' => $valid ? 'valid' : 'invalid',
            'counted' => $counted,
            'dropped_iterations' => $droppedIterations,
            'invalid_reasons' => $reasons,
        ]);
    }
}
