<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Predict;

use PeakSim\Runtime\Simulate\Calibrate\CalibrationFit;
use PeakSim\Runtime\Simulate\Calibrate\CalibrationPoint;
use PeakSim\Runtime\Simulate\Calibrate\CalibrationResult;
use PeakSim\Runtime\Simulate\Calibrate\Calibrator;

/**
 * The width that the calibration alone gives a prediction (simulator-spec.md section 6.3, as changed on 2026-11-09).
 *
 *  - three or more calibration scenarios: the two-coefficient fits made by leaving one scenario out, one at a time, beside the
 *    fit on all of them (the fit that gives the point value is always inside the range, so the point is never outside its own width).
 *    The one-coefficient fits are not mixed in: when two coefficients can be fixed, a one-coefficient fit only widens the range without
 *    saying anything about the calibration.
 *  - two scenarios: the two coefficients are fixed exactly, and leaving one out leaves a single scenario, which cannot fix two. The width of
 *    the calibration cannot be given. This is said in the output; no width is put in its place.
 *  - one scenario (or scenarios that cannot tell the two coefficients apart): the queries-only and rows-only fits of section 5.
 *
 * The fitting itself is Calibrator's; this class only calls it on the subsets. No randomness, no clock.
 */
final class CalibrationWidth
{
    public const LEAVE_ONE_OUT = 'leave_one_out';

    public const ONE_COEFFICIENT = 'one_coefficient_pair';

    public const UNAVAILABLE = 'unavailable';

    /** The label of the fit on all the calibration scenarios in a leave-one-out width. */
    public const ALL = 'all';

    /**
     * @param list<array{label: string, fit: CalibrationFit}> $fits   the fits whose range is the width (empty when unavailable)
     * @param list<string>                                    $notes  anything the reader must be told (a subset that could not be fitted)
     */
    private function __construct(
        public readonly string $status,
        public readonly string $reason,
        public readonly array $fits,
        public readonly array $notes,
    ) {
    }

    /**
     * @param list<CalibrationPoint> $points the calibration scenarios the calibration was made from
     */
    public static function of(array $points, CalibrationResult $calibration): self
    {
        if ($calibration->method === CalibrationResult::METHOD_ONE) {
            return self::onlyTheResult($calibration);
        }
        $points = array_values($points);
        $n = count($points);
        if ($n < 3) {
            return new self(
                self::UNAVAILABLE,
                $n === 2
                    ? 'two calibration scenarios fix the two coefficients exactly; leaving one out leaves a single scenario, which cannot fix two, so the width of the calibration cannot be given (add a third calibration scenario)'
                    : 'fewer than three calibration scenarios: the width of the calibration cannot be given',
                [],
                [],
            );
        }

        $point = null;
        foreach ($calibration->fits as $fit) {
            if ($fit->kind === CalibrationFit::TWO) {
                $point = $fit;
            }
        }
        if ($point === null) {
            return new self(self::UNAVAILABLE, 'the calibration has no two-coefficient fit', [], []);
        }
        $fits = [['label' => self::ALL, 'fit' => $point]];
        $notes = [];
        $calibrator = new Calibrator();
        foreach ($points as $i => $left) {
            $rest = $points;
            unset($rest[$i]);
            $sub = $calibrator->calibrate(array_values($rest));
            $two = null;
            if ($sub->method === CalibrationResult::METHOD_TWO) {
                foreach ($sub->fits as $fit) {
                    if ($fit->kind === CalibrationFit::TWO) {
                        $two = $fit;
                    }
                }
            }
            if ($two === null) {
                $notes[] = sprintf('without %s: the remaining scenarios cannot tell the two coefficients apart; this subset is not in the range', $left->id);

                continue;
            }
            $fits[] = ['label' => 'without:' . $left->id, 'fit' => $two];
        }
        if (count($fits) === 1) {
            return new self(self::UNAVAILABLE, 'no subset of the calibration scenarios can fix the two coefficients', [], $notes);
        }

        return new self(self::LEAVE_ONE_OUT, sprintf('the range of the two-coefficient fits made by leaving each of the %d calibration scenarios out', $n), $fits, $notes);
    }

    /**
     * The width when the points are not at hand (a calibration given only as a result): a one-coefficient pair has its two fits, a
     * two-coefficient fit has no width.
     */
    public static function ofResult(CalibrationResult $calibration): self
    {
        return $calibration->method === CalibrationResult::METHOD_ONE
            ? self::onlyTheResult($calibration)
            : new self(self::UNAVAILABLE, 'the calibration scenarios are not given, so the width of the calibration cannot be made', [], []);
    }

    private static function onlyTheResult(CalibrationResult $calibration): self
    {
        $fits = [];
        foreach ($calibration->fits as $fit) {
            $fits[] = ['label' => $fit->kind, 'fit' => $fit];
        }

        return new self(
            self::ONE_COEFFICIENT,
            'one calibration scenario (or scenarios that cannot tell the two coefficients apart): the queries-only and the rows-only fits',
            $fits,
            [],
        );
    }

    public function isAvailable(): bool
    {
        return $this->fits !== [];
    }

    /**
     * The load L of the width's fits for the two rates of a scenario, keyed by fit label. Empty when the width is not available.
     *
     * @return array<string, float>
     */
    public function loads(float $queryRate, float $rowRate): array
    {
        $out = [];
        foreach ($this->fits as $f) {
            $out[$f['label']] = $f['fit']->load($queryRate, $rowRate);
        }

        return $out;
    }

    /** @return list<string> */
    public function labels(): array
    {
        return array_map(static fn (array $f): string => $f['label'], $this->fits);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'fits' => array_map(static fn (array $f): array => [
                'label' => $f['label'],
                'c_query' => $f['fit']->cQuery,
                'c_row' => $f['fit']->cRow,
                'cut_coefficient' => $f['fit']->hasCutCoefficient(),
            ], $this->fits),
        ];
    }
}
