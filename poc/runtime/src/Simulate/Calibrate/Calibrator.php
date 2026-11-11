<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Calibrate;

use InvalidArgumentException;

/**
 * Fixes c_q and c_row from the calibration scenarios (simulator-spec.md section 5).
 *
 *     L_obs = observed utilisation * cores  ~  c_q * queryRate + c_row * rowRate        c_q >= 0, c_row >= 0
 *
 * Non-negative least squares for two unknowns is solved exactly, with no iteration and no randomness:
 *  - the plain two-unknown least squares; if both come out >= 0 it is the answer;
 *  - otherwise the optimum lies on the boundary, so it is the better of "c_q only" and "c_row only" (each cut at 0).
 *
 * A coefficient that comes out negative, or that ends pinned at 0, is recorded in the fit and in the warnings. It is never
 * used as 0 without saying so.
 *
 * When the scenarios cannot tell the two coefficients apart (one scenario, or every scenario has the same ratio of
 * queries to rows) the two-unknown fit does not exist; the method is then one-coefficient (queries only and rows only) and
 * a note says why. No weight between the two is assumed.
 */
final class Calibrator
{
    public const C_QUERY = 'c_query';

    public const C_ROW = 'c_row';

    /** Below this share of Saa * Sbb the 2x2 system is treated as singular (the two rates are proportional). */
    public const SINGULAR_TOLERANCE = 1e-12;

    /** Two sums of squared residuals closer than this share of the observed sum of squares are the same error. */
    public const TIE_TOLERANCE = 1e-12;

    /**
     * @param list<CalibrationPoint> $points
     */
    public function calibrate(array $points): CalibrationResult
    {
        $points = array_values($points);
        if ($points === []) {
            throw new InvalidArgumentException('Calibration needs at least one calibration scenario.');
        }

        $unknown = array_sum(array_map(static fn (CalibrationPoint $p): int => $p->unknownCostRoutes, $points));
        $queriesOnly = $this->oneCoefficient($points, CalibrationFit::QUERIES_ONLY);
        $rowsOnly = $this->oneCoefficient($points, CalibrationFit::ROWS_ONLY);

        $two = count($points) >= 2 ? $this->twoCoefficient($points) : null;
        $notes = [];
        if ($two === null) {
            $method = CalibrationResult::METHOD_ONE;
            $fits = [$queriesOnly, $rowsOnly];
            if (count($points) >= 2) {
                $notes[] = 'The calibration scenarios have the same ratio of queries to rows, so c_q and c_row cannot be told apart; the two one-coefficient fits are used.';
            }
        } else {
            $method = CalibrationResult::METHOD_TWO;
            $fits = [$two, $queriesOnly, $rowsOnly];
        }

        return new CalibrationResult($method, count($points), $fits, [...$notes, ...$this->warnings($fits)], $unknown);
    }

    /** @param list<CalibrationPoint> $points */
    private function twoCoefficient(array $points): ?CalibrationFit
    {
        [$saa, $sbb, $sab, $say, $sby, $syy] = self::sums($points);
        $det = $saa * $sbb - $sab * $sab;
        if ($saa <= 0.0 || $sbb <= 0.0 || $det <= self::SINGULAR_TOLERANCE * $saa * $sbb) {
            return null;
        }

        $cq = ($say * $sbb - $sby * $sab) / $det;
        $cr = ($saa * $sby - $sab * $say) / $det;
        $negative = [];
        if ($cq < 0.0) {
            $negative[] = self::C_QUERY;
        }
        if ($cr < 0.0) {
            $negative[] = self::C_ROW;
        }
        if ($negative !== []) {
            // The optimum is on the boundary: the better of the two single-coefficient solutions, each cut at 0.
            $onlyQ = [max(0.0, $say / $saa), 0.0];
            $onlyR = [0.0, max(0.0, $sby / $sbb)];
            $sseQ = $this->sse($points, $onlyQ[0], $onlyQ[1]);
            $sseR = $this->sse($points, $onlyR[0], $onlyR[1]);
            // On a tie the queries-only solution wins (a fixed order, so the same input gives the same output).
            [$cq, $cr] = $sseR < $sseQ - self::TIE_TOLERANCE * max($syy, 1e-300) ? $onlyR : $onlyQ;
        }

        $atZero = [];
        if ($cq === 0.0) {
            $atZero[] = self::C_QUERY;
        }
        if ($cr === 0.0) {
            $atZero[] = self::C_ROW;
        }

        return $this->fit(CalibrationFit::TWO, $points, $cq, $cr, $negative, $atZero, []);
    }

    /** @param list<CalibrationPoint> $points */
    private function oneCoefficient(array $points, string $kind): CalibrationFit
    {
        $queries = $kind === CalibrationFit::QUERIES_ONLY;
        $name = $queries ? self::C_QUERY : self::C_ROW;
        $num = 0.0;
        $den = 0.0;
        foreach ($points as $p) {
            $x = $queries ? $p->queryRate : $p->rowRate;
            $num += $x * $p->observedLoad();
            $den += $x * $x;
        }

        $notes = [];
        $negative = [];
        if ($den <= 0.0) {
            $c = 0.0;
            $notes[] = sprintf('%s has no signal: every calibration scenario has a rate of 0 for it.', $name);
        } else {
            $c = $num / $den;
            if ($c < 0.0) {
                $negative[] = $name;
                $c = 0.0;
            }
        }
        $atZero = $c === 0.0 ? [$name] : [];

        return $this->fit($kind, $points, $queries ? $c : 0.0, $queries ? 0.0 : $c, $negative, $atZero, $notes);
    }

    /**
     * @param list<CalibrationPoint> $points
     * @param list<string>           $negative
     * @param list<string>           $atZero
     * @param list<string>           $notes
     */
    private function fit(string $kind, array $points, float $cq, float $cr, array $negative, array $atZero, array $notes): CalibrationFit
    {
        $residuals = [];
        $sse = 0.0;
        foreach ($points as $p) {
            $fitted = $cq * $p->queryRate + $cr * $p->rowRate;
            $residual = $p->observedLoad() - $fitted;
            $sse += $residual * $residual;
            $residuals[] = [
                'id' => $p->id,
                'observed_load' => $p->observedLoad(),
                'fitted_load' => $fitted,
                'residual_load' => $residual,
                'observed_utilization' => $p->observedUtilization,
                'fitted_utilization' => $fitted / $p->cores,
                'residual_utilization' => $residual / $p->cores,
            ];
        }

        return new CalibrationFit($kind, $cq, $cr, $negative, $atZero, $notes, $residuals, $sse);
    }

    /** @param list<CalibrationPoint> $points */
    private function sse(array $points, float $cq, float $cr): float
    {
        $sse = 0.0;
        foreach ($points as $p) {
            $d = $p->observedLoad() - ($cq * $p->queryRate + $cr * $p->rowRate);
            $sse += $d * $d;
        }

        return $sse;
    }

    /**
     * The sums of the normal equations. Public so that the gate that comes before the calibration ({@see CalibrationGate}) works from the same sums.
     *
     * @param list<CalibrationPoint> $points
     *
     * @return array{float, float, float, float, float, float} Saa, Sbb, Sab, Say, Sby, Syy
     */
    public static function sums(array $points): array
    {
        $saa = $sbb = $sab = $say = $sby = $syy = 0.0;
        foreach ($points as $p) {
            $y = $p->observedLoad();
            $saa += $p->queryRate * $p->queryRate;
            $sbb += $p->rowRate * $p->rowRate;
            $sab += $p->queryRate * $p->rowRate;
            $say += $p->queryRate * $y;
            $sby += $p->rowRate * $y;
            $syy += $y * $y;
        }

        return [$saa, $sbb, $sab, $say, $sby, $syy];
    }

    /**
     * @param list<CalibrationFit> $fits
     *
     * @return list<string>
     */
    private function warnings(array $fits): array
    {
        $warnings = [];
        foreach ($fits as $fit) {
            foreach ($fit->negativeUnconstrained as $name) {
                $warnings[] = sprintf('%s: %s came out negative in the plain least squares and is pinned at 0.', $fit->kind, $name);
            }
            foreach ($fit->atZero as $name) {
                if (!in_array($name, $fit->negativeUnconstrained, true)) {
                    $warnings[] = sprintf('%s: %s is exactly 0.', $fit->kind, $name);
                }
            }
            foreach ($fit->notes as $note) {
                $warnings[] = sprintf('%s: %s', $fit->kind, $note);
            }
        }

        return $warnings;
    }
}
