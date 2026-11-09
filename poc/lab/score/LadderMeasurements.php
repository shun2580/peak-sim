<?php

declare(strict_types=1);

namespace PeakSim\Lab\Score;

/**
 * Reads the record of one scenario's ladder (the `ladder.json` the measurement writes: {scenario, rungs: [...]}) into the measured
 * stages LadderInput::join() takes. Only four items of a step's record are used: `scenario`, `decided_rps`, `rps_valid`
 * and `db_utilization` (a share, 0 to 1). Nothing is judged and nothing is recomputed here.
 */
final class LadderMeasurements
{
    /**
     * @param list<array<string, mixed>> $ladders the decoded files, one per scenario
     *
     * @return array<string, list<array{rps: float|int, valid: bool, utilization: float|int}>>
     *
     * @throws \InvalidArgumentException
     */
    public static function fromLadders(array $ladders): array
    {
        $out = [];
        foreach ($ladders as $ladder) {
            if (!is_array($ladder) || !isset($ladder['scenario']) || !is_string($ladder['scenario']) || $ladder['scenario'] === '') {
                throw new \InvalidArgumentException('a ladder record has no scenario');
            }
            $id = $ladder['scenario'];
            if (isset($out[$id])) {
                throw new \InvalidArgumentException('the scenario "' . $id . '" is in two ladder records');
            }
            if (!isset($ladder['rungs']) || !is_array($ladder['rungs'])) {
                throw new \InvalidArgumentException('the ladder record of "' . $id . '" has no rungs');
            }
            $stages = [];
            foreach ($ladder['rungs'] as $rung) {
                if (!is_array($rung) || ($rung['scenario'] ?? $id) !== $id) {
                    throw new \InvalidArgumentException('a step of the ladder record of "' . $id . '" belongs to another scenario');
                }
                foreach (['decided_rps', 'db_utilization'] as $key) {
                    if (!isset($rung[$key]) || (!is_int($rung[$key]) && !is_float($rung[$key]))) {
                        throw new \InvalidArgumentException('a step of "' . $id . '" has no number in ' . $key);
                    }
                }
                if (!isset($rung['rps_valid']) || !is_bool($rung['rps_valid'])) {
                    throw new \InvalidArgumentException('a step of "' . $id . '" has no true/false rps_valid');
                }
                $stages[] = ['rps' => $rung['decided_rps'], 'valid' => $rung['rps_valid'], 'utilization' => $rung['db_utilization']];
            }
            $out[$id] = $stages;
        }

        return $out;
    }
}
