<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Calibrate;

/**
 * One way of fixing the coefficients: the two-coefficient fit, or one of the one-coefficient fits.
 * A coefficient that is not part of the fit is 0 and `fitted` does not list it.
 */
final class CalibrationFit
{
    public const TWO = 'two_coefficient';

    public const QUERIES_ONLY = 'queries_only';

    public const ROWS_ONLY = 'rows_only';

    /**
     * @param list<string> $negativeUnconstrained coefficients whose plain least-squares value was below 0 (then cut to 0)
     * @param list<string> $atZero                coefficients that ended at exactly 0 (a coefficient that was cut counts)
     * @param list<string> $notes                 anything else the reader must be told (no signal, collinear input)
     * @param list<array{id: string, observed_load: float, fitted_load: float, residual_load: float, observed_utilization: float, fitted_utilization: float, residual_utilization: float}> $residuals
     */
    public function __construct(
        public readonly string $kind,
        public readonly float $cQuery,
        public readonly float $cRow,
        public readonly array $negativeUnconstrained,
        public readonly array $atZero,
        public readonly array $notes,
        public readonly array $residuals,
        public readonly float $sumSquaredResidual,
    ) {
    }

    /** L for the two rates (sum of rps * queries, sum of rps * rows). */
    public function load(float $queryRate, float $rowRate): float
    {
        return $this->cQuery * $queryRate + $this->cRow * $rowRate;
    }

    /** True when the coefficients had to be cut or pinned: the output must say so. */
    public function hasCutCoefficient(): bool
    {
        return $this->negativeUnconstrained !== [] || $this->atZero !== [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'c_query' => $this->cQuery,
            'c_row' => $this->cRow,
            'negative_unconstrained' => $this->negativeUnconstrained,
            'at_zero' => $this->atZero,
            'notes' => $this->notes,
            'sum_squared_residual_load' => $this->sumSquaredResidual,
            'residuals' => $this->residuals,
        ];
    }
}
