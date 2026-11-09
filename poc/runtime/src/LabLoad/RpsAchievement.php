<?php

declare(strict_types=1);

namespace PeakSim\Runtime\LabLoad;

/**
 * Did the decided rps actually come out (spec 7.2: within 5%)? Counted over the kept part of the scenario only, the
 * requests that started after the discarded start. A scenario that missed is "invalid" and is recorded as such.
 */
final class RpsAchievement
{
    public const TOLERANCE = 0.05;

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
}
