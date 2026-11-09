<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Predict;

use PeakSim\Runtime\Simulate\Calibrate\CalibrationFit;
use PeakSim\Runtime\Simulate\Calibrate\CalibrationResult;

/**
 * One calibration of the load model (simulator-spec.md section 5): L = sum over routes of rps * (c_q * queries + c_row * rows).
 * A one-coefficient calibration has the other coefficient at 0 (queries only: c_row = 0; rows only: c_q = 0).
 */
final class Coefficients
{
    public function __construct(
        public readonly string $name,
        public readonly float $cQ,
        public readonly float $cRow,
    ) {
        if ($cQ < 0.0 || $cRow < 0.0) {
            throw new \InvalidArgumentException('a coefficient of the load model cannot be negative');
        }
    }

    /**
     * The coefficients that give the point value of a calibration: the two-coefficient fit, or for one-coefficient fits the mean of the
     * fits (the point value there is the mean of their predictions, and a prediction is linear in the coefficients).
     */
    public static function pointOf(CalibrationResult $calibration): self
    {
        if ($calibration->fits === []) {
            throw new \InvalidArgumentException('a calibration with no fit has no point value');
        }
        if ($calibration->method === CalibrationResult::METHOD_TWO) {
            foreach ($calibration->fits as $fit) {
                if ($fit->kind === CalibrationFit::TWO) {
                    return new self($fit->kind, $fit->cQuery, $fit->cRow);
                }
            }
            throw new \InvalidArgumentException('a two-coefficient calibration has no two-coefficient fit');
        }
        $q = 0.0;
        $r = 0.0;
        foreach ($calibration->fits as $fit) {
            $q += $fit->cQuery;
            $r += $fit->cRow;
        }
        $n = count($calibration->fits);

        return new self('mean_of_one_coefficient_fits', $q / $n, $r / $n);
    }

    /** @return array{name: string, c_q: float, c_row: float} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'c_q' => $this->cQ, 'c_row' => $this->cRow];
    }
}
