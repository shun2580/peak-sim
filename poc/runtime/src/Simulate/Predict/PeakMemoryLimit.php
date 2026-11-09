<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Predict;

use PeakSim\Runtime\Judge\CostFit;

/**
 * The count N at which one request of a route reaches php_memory_limit x 0.8 (simulator-spec.md section 6.2). It does not depend on traffic.
 * Only a route whose peak-memory formula held within +-30% in the extrapolation tests is given a number; the others say why not.
 *
 * N is the smallest whole count with a + b x N^k above the line, found by bisection on integers (the formula never decreases:
 * a, b >= 0 and k >= 0), so the same input gives the same N.
 */
final class PeakMemoryLimit
{
    public const SHARE = 0.8;

    /** The largest N looked at. A line not reached by here is reported as "not reached". */
    public const CAP = 1_000_000_000_000_000;

    public const SHOWN = 'shown';

    public const NOT_SHOWN = 'not_shown';

    public const REASON_ACCURACY = 'insufficient_accuracy';

    public const REASON_NOT_CHECKED = 'accuracy_not_checked';

    public const REASON_NO_FORMULA = 'no_formula';

    public const REASON_NO_LIMIT = 'php_memory_limit_unknown';

    /**
     * @param array<string, array{table: string|null, a: float, b: float, k: float}|null> $formulas route => formula (null: none)
     *
     * @return list<array<string, mixed>>
     */
    public static function all(array $formulas, MemoryAccuracy $accuracy, ?int $phpMemoryLimitBytes): array
    {
        ksort($formulas);
        $line = $phpMemoryLimitBytes === null ? null : self::SHARE * $phpMemoryLimitBytes;
        $out = [];
        foreach ($formulas as $route => $f) {
            $entry = ['route' => (string) $route, 'table' => $f['table'] ?? null, 'line_bytes' => $line];
            $state = $accuracy->stateOf((string) $route);
            if ($f === null) {
                $out[] = $entry + ['status' => self::NOT_SHOWN, 'reason' => self::REASON_NO_FORMULA, 'n' => null];
            } elseif ($line === null) {
                $out[] = $entry + ['status' => self::NOT_SHOWN, 'reason' => self::REASON_NO_LIMIT, 'n' => null];
            } elseif ($state === MemoryAccuracy::OUTSIDE) {
                $out[] = $entry + ['status' => self::NOT_SHOWN, 'reason' => self::REASON_ACCURACY, 'n' => null];
            } elseif ($state === MemoryAccuracy::NOT_CHECKED) {
                $out[] = $entry + ['status' => self::NOT_SHOWN, 'reason' => self::REASON_NOT_CHECKED, 'n' => null];
            } else {
                $out[] = $entry + ['status' => self::SHOWN, 'reason' => null, 'n' => self::firstOver($f['a'], $f['b'], $f['k'], $line)];
            }
        }

        return $out;
    }

    /**
     * The smallest N >= 0 with a + b x N^k > line; 0 when the empty system is already over; null when CAP is not over.
     */
    public static function firstOver(float $a, float $b, float $k, float $line): ?int
    {
        if (CostFit::evaluate($a, $b, $k, 0) > $line) {
            return 0;
        }
        if (CostFit::evaluate($a, $b, $k, self::CAP) <= $line) {
            return null;
        }
        $low = 0; // not over
        $high = self::CAP; // over
        while ($high - $low > 1) {
            $mid = $low + intdiv($high - $low, 2);
            if (CostFit::evaluate($a, $b, $k, $mid) > $line) {
                $high = $mid;
            } else {
                $low = $mid;
            }
        }

        return $high;
    }
}
