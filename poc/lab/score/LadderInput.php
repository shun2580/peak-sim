<?php

declare(strict_types=1);

namespace PeakSim\Lab\Score;

/**
 * Puts the prediction file of the ladder (kind "lab-ladder-prediction") and the measured stages side by side in the form
 * LadderScore::score() reads. It adds no number of its own: a prediction is copied as it is, a measurement is copied as it is.
 *
 * The measured stages come as: scenario id => list of ['rps' => the decided total rps of the stage, 'valid' => bool, 'utilization' => 0..1].
 * Reading the files of a measurement into that form is the job of whatever wrote them (see LadderMeasurements).
 *
 * Joining rules (a mismatch is an error, never a silent drop):
 *  - a measured stage is matched to a predicted stage of the same scenario by its decided rps (the ladder values, to a relative 1e-9)
 *  - a measured stage with no predicted stage, a measured scenario that has no prediction, and two measurements of one stage are refused
 *  - a predicted stage with no measurement is "not measured" (the scenario stopped before it). A scenario with no measurement at all is
 *    listed, with every stage not measured
 */
final class LadderInput
{
    public const PREDICTION_KIND = 'lab-ladder-prediction';

    /** Two rps are the same ladder step within this relative difference. */
    private const SAME_RPS = 1e-9;

    /**
     * @param array<string, mixed>                                                               $prediction the decoded prediction file
     * @param array<string, list<array{rps: float|int, valid: bool, utilization: float|int}>>    $measured
     *
     * @return array<string, mixed> the input of LadderScore::score()
     *
     * @throws \InvalidArgumentException
     */
    public static function join(array $prediction, array $measured): array
    {
        if (($prediction['kind'] ?? null) !== self::PREDICTION_KIND) {
            throw new \InvalidArgumentException('the prediction file is not of kind "' . self::PREDICTION_KIND . '"');
        }
        $threshold = $prediction['resources']['threshold'] ?? null;
        if (!is_int($threshold) && !is_float($threshold)) {
            throw new \InvalidArgumentException('the prediction file has no threshold');
        }
        if (!isset($prediction['scenarios']) || !is_array($prediction['scenarios']) || $prediction['scenarios'] === []) {
            throw new \InvalidArgumentException('the prediction file has no scenario');
        }

        $known = [];
        foreach ($prediction['scenarios'] as $s) {
            if (is_array($s) && isset($s['id']) && is_string($s['id'])) {
                $known[$s['id']] = true;
            }
        }
        foreach (array_keys($measured) as $id) {
            if (!isset($known[(string) $id])) {
                throw new \InvalidArgumentException('the measurement of "' . $id . '" has no scenario in the prediction file');
            }
        }

        $scenarios = [];
        foreach ($prediction['scenarios'] as $s) {
            if (!is_array($s) || !isset($s['id']) || !is_string($s['id']) || !isset($s['stages']) || !is_array($s['stages'])) {
                throw new \InvalidArgumentException('a scenario of the prediction file has no id or no stages');
            }
            $id = $s['id'];
            $mine = $measured[$id] ?? [];
            $used = [];
            $stages = [];
            foreach ($s['stages'] as $row) {
                $stages[] = self::stage($id, $row, $mine, $used);
            }
            foreach (array_keys($mine) as $i) {
                if (!isset($used[$i])) {
                    throw new \InvalidArgumentException(
                        'the measured stage at rps ' . (string) ($mine[$i]['rps'] ?? '?') . ' of "' . $id . '" is not a stage of the predicted ladder',
                    );
                }
            }
            $scenarios[] = [
                'id' => $id,
                'stages' => $stages,
                'predicted_limit_rps' => [
                    'point' => self::limit($s['limit_rps']['point'] ?? null, $id, 'point'),
                    'safe' => self::limit($s['limit_rps']['safe'] ?? null, $id, 'safe'),
                    'naive' => self::limit($s['limit_rps']['naive'] ?? null, $id, 'naive'),
                ],
            ];
        }

        return ['threshold' => (float) $threshold, 'scenarios' => $scenarios];
    }

    /**
     * @param array<string, mixed>   $row
     * @param list<array>            $mine
     * @param array<int, true>       $used
     *
     * @return array<string, mixed>
     */
    private static function stage(string $id, mixed $row, array $mine, array &$used): array
    {
        if (!is_array($row) || !isset($row['rps_total']) || !isset($row['utilization']['point'], $row['utilization']['upper'], $row['naive'])) {
            throw new \InvalidArgumentException('a stage of "' . $id . '" in the prediction file lacks rps_total, utilization.point, utilization.upper or naive');
        }
        $rps = (float) $row['rps_total'];
        $found = null;
        foreach ($mine as $i => $m) {
            if (!is_array($m) || !isset($m['rps']) || (!is_int($m['rps']) && !is_float($m['rps']))) {
                throw new \InvalidArgumentException('a measured stage of "' . $id . '" has no rps');
            }
            if (abs((float) $m['rps'] - $rps) <= self::SAME_RPS * max(abs($rps), 1.0)) {
                if ($found !== null) {
                    throw new \InvalidArgumentException('the stage at rps ' . $rps . ' of "' . $id . '" is measured twice');
                }
                $found = $i;
            }
        }
        $measured = null;
        if ($found !== null) {
            $used[$found] = true;
            $m = $mine[$found];
            if (!isset($m['valid']) || !is_bool($m['valid']) || !isset($m['utilization'])) {
                throw new \InvalidArgumentException('the measured stage at rps ' . $rps . ' of "' . $id . '" lacks valid (true/false) or utilization');
            }
            $measured = ['valid' => $m['valid'], 'utilization' => $m['utilization']];
        }

        return [
            'rps' => $rps,
            'predicted' => ['point' => $row['utilization']['point'], 'safe' => $row['utilization']['upper'], 'naive' => $row['naive']],
            'measured' => $measured,
        ];
    }

    /** @return float|null null where the prediction has no limit (no load on the database) */
    private static function limit(mixed $entry, string $id, string $kind): ?float
    {
        if (!is_array($entry) || !array_key_exists('status', $entry) || !array_key_exists('rps', $entry)) {
            throw new \InvalidArgumentException('the ' . $kind . ' limit of "' . $id . '" in the prediction file has no status and rps');
        }
        if ($entry['status'] === 'found') {
            if (!is_int($entry['rps']) && !is_float($entry['rps'])) {
                throw new \InvalidArgumentException('the ' . $kind . ' limit of "' . $id . '" is "found" but has no number');
            }

            return (float) $entry['rps'];
        }
        if ($entry['status'] === 'no_load' && $entry['rps'] === null) {
            return null;
        }
        throw new \InvalidArgumentException('the ' . $kind . ' limit of "' . $id . '" has an unknown status');
    }
}
