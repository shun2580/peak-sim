<?php

declare(strict_types=1);

namespace PeakSim\Runtime\LabLoad;

/**
 * Reads a recorded step again with the rule of section 7.4.3 and puts the result beside what the record says. It only reads: a record, a
 * verdict or a file is never changed by it (the earlier backtests keep the verdicts they were given).
 *
 * The inputs are the three files a step already keeps: `plan.json`, the load tool's `summary.json` and `record.json`. A step recorded
 * before the load tool counted the requests it sent has no sent count, so its finished requests are counted instead
 * (`counted: completed`); for it the new rule can differ from the old one only through the dropped arrivals.
 */
final class StepReread
{
    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $k6 the load tool's summary of the step
     * @param array<string, mixed> $record the step's record as it was written
     *
     * @return array<string, mixed>
     */
    public static function step(array $plan, array $k6, array $record): array
    {
        $window = (int) $plan['duration_sec'] - (int) $plan['warmup_sec'];
        $hasSent = isset($k6['measure_sent']);
        $count = (int) ($hasSent ? $k6['measure_sent'] : $k6['measure_requests']);
        $new = RpsAchievement::judgeStep(
            (float) $plan['rps'],
            $count,
            $window,
            (int) ($k6['dropped_iterations'] ?? 0),
            $hasSent ? RpsAchievement::COUNTED_SENT : RpsAchievement::COUNTED_COMPLETED,
        );
        $oldValid = (bool) $record['rps_valid'];
        $oldCount = (int) $record['requests_in_window'];

        return [
            'scenario' => $record['scenario'],
            'rung' => $record['rung'],
            'decided_rps' => $record['decided_rps'],
            'window_sec' => $window,
            'requests_completed_in_window' => (int) $k6['measure_requests'],
            'requests_sent_in_window' => $hasSent ? (int) $k6['measure_sent'] : null,
            'dropped_iterations' => (int) ($k6['dropped_iterations'] ?? 0),
            'counted' => $new['counted'],
            'old_count' => $oldCount,
            'new_count' => $count,
            'old_valid' => $oldValid,
            'new_valid' => $new['valid'],
            'new_invalid_reasons' => $new['invalid_reasons'],
            'same_count' => $oldCount === $count,
            'same_verdict' => $oldValid === $new['valid'],
        ];
    }

    /**
     * Every step of a scenario directory that holds the three files, in the order of the steps.
     *
     * @return list<array<string, mixed>>
     */
    public static function scenarioDir(string $dir): array
    {
        $rows = [];
        $steps = glob(rtrim($dir, '/') . '/rung-*', GLOB_ONLYDIR) ?: [];
        sort($steps, SORT_STRING);
        foreach ($steps as $step) {
            $files = [];
            foreach (['plan', 'summary', 'record'] as $name) {
                $path = $step . '/' . $name . '.json';
                $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
                if (!is_array($data)) {
                    continue 2; // a step without all three (it was not recorded) is not read
                }
                $files[$name] = $data;
            }
            $rows[] = self::step($files['plan'], $files['summary'], $files['record']);
        }

        return $rows;
    }
}
