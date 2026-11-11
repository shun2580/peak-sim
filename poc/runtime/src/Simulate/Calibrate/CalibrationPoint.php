<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Calibrate;

use InvalidArgumentException;

/**
 * One calibration scenario, reduced to the three numbers the load formula needs (simulator-spec.md section 5):
 *
 *     L = sum over routes r of  rps_r * ( c_q * queries_r(N) + c_row * rows_r(N) )
 *
 * `queryRate` is sum(rps_r * queries_r(N)) and `rowRate` is sum(rps_r * rows_r(N)), both already evaluated at the
 * scenario's own table counts. `observedLoad` is the observed utilisation times the cores, so L is in "cores busy".
 */
final class CalibrationPoint
{
    public function __construct(
        public readonly string $id,
        public readonly float $queryRate,
        public readonly float $rowRate,
        public readonly float $observedUtilization,
        public readonly float $cores,
        /** Routes with an unknown cost: left out of the two rates, never counted as 0 silently. */
        public readonly int $unknownCostRoutes = 0,
    ) {
        foreach (['queryRate' => $queryRate, 'rowRate' => $rowRate, 'observedUtilization' => $observedUtilization] as $name => $value) {
            if (!is_finite($value) || $value < 0.0) {
                throw new InvalidArgumentException(sprintf('Calibration point "%s": %s must be a finite number >= 0.', $id, $name));
            }
        }
        if (!is_finite($cores) || $cores <= 0.0) {
            throw new InvalidArgumentException(sprintf('Calibration point "%s": cores must be a finite number > 0.', $id));
        }
    }

    /**
     * The two rates of a scenario from its routes: sum(rps_r * queries_r) and sum(rps_r * rows_r), and how many routes had an unknown cost
     * (left out). The calibration and the gate that comes before it ({@see CalibrationGate}) both take their rates from here.
     *
     * @param list<array{rps: int|float, queries: int|float|null, rows: int|float|null}> $routes a null cost is "unknown"
     *
     * @return array{float, float, int} queryRate, rowRate, unknown
     */
    public static function rates(array $routes): array
    {
        $queryRate = 0.0;
        $rowRate = 0.0;
        $unknown = 0;
        foreach ($routes as $route) {
            if ($route['queries'] === null || $route['rows'] === null) {
                ++$unknown;

                continue;
            }
            $queryRate += $route['rps'] * $route['queries'];
            $rowRate += $route['rps'] * $route['rows'];
        }

        return [$queryRate, $rowRate, $unknown];
    }

    /**
     * @param list<array{rps: int|float, queries: int|float|null, rows: int|float|null}> $routes a null cost is "unknown"
     */
    public static function fromRoutes(string $id, float $cores, float $observedUtilization, array $routes): self
    {
        [$queryRate, $rowRate, $unknown] = self::rates($routes);

        return new self($id, $queryRate, $rowRate, $observedUtilization, $cores, $unknown);
    }

    public function observedLoad(): float
    {
        return $this->observedUtilization * $this->cores;
    }
}
