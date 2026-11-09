<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Calibrate;

/**
 * What a calibration hands to the prediction step (simulator-spec.md sections 5 and 6.3).
 *
 * With two or more usable calibration scenarios the point value is the two-coefficient fit, and the one-coefficient fits
 * (queries only, rows only) widen the range. With one scenario (or inputs that cannot tell the two coefficients apart) the
 * point value is the middle of the two one-coefficient predictions, and both bound the range.
 */
final class CalibrationResult
{
    public const METHOD_TWO = 'two_coefficient';

    public const METHOD_ONE = 'one_coefficient';

    /**
     * @param list<CalibrationFit> $fits the point fit first when the method is two-coefficient
     * @param list<string>         $warnings
     */
    public function __construct(
        public readonly string $method,
        public readonly int $pointCount,
        public readonly array $fits,
        public readonly array $warnings,
        public readonly int $unknownCostRoutes,
    ) {
    }

    /**
     * The load L for a scenario's two rates: the point value, and the smallest and largest over all fits.
     *
     * @return array{point: float, min: float, max: float, by_fit: array<string, float>}
     */
    public function predict(float $queryRate, float $rowRate): array
    {
        $byFit = [];
        foreach ($this->fits as $fit) {
            $byFit[$fit->kind] = $fit->load($queryRate, $rowRate);
        }
        $values = array_values($byFit);
        $point = $this->method === self::METHOD_TWO
            ? $byFit[CalibrationFit::TWO]
            : array_sum($values) / count($values);

        return ['point' => $point, 'min' => min($values), 'max' => max($values), 'by_fit' => $byFit];
    }

    /** True when any fit cut or pinned a coefficient. */
    public function hasCutCoefficient(): bool
    {
        foreach ($this->fits as $fit) {
            if ($fit->hasCutCoefficient()) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'calibration_scenarios' => $this->pointCount,
            'cut_coefficients' => $this->hasCutCoefficient(),
            'warnings' => $this->warnings,
            'unknown_cost_routes' => $this->unknownCostRoutes,
            'fits' => array_map(static fn (CalibrationFit $f): array => $f->toArray(), $this->fits),
        ];
    }
}
