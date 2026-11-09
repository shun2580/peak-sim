<?php

declare(strict_types=1);

namespace PeakSim\Runtime\LabLoad;

/**
 * The rps ladder of the lab backtest's prediction scenarios (docs/simulator-spec.md section 7.2): which steps, how long each
 * lasts, what each scenario is, when a scenario stops, and what is written down for one step.
 *
 * Nothing here is a model of the lab. The steps, the length of a step, the threshold and the stopping rule were fixed before
 * the prediction was made; this class only holds them in one place and turns a step's figures into its record.
 */
final class Ladder
{
    /** The total rps of each step, from the bottom up (section 7.2). */
    public const RUNGS = [0.05, 0.07, 0.1, 0.15, 0.2, 0.3, 0.45, 0.7, 1.0, 1.5, 2.2, 3.3, 5.0];

    /** One step: 6 minutes of constant arrival, the first minute discarded. */
    public const DURATION_SEC = 360;
    public const WARMUP_SEC = 60;

    /** The DB utilization (a fraction) at or above which the scenario stops (section 7.2). */
    public const STOP_UTILIZATION = 0.80;

    /** N_0 (section 7.2). */
    public const BASE_SCALE = 10000;

    /** The app container's CPUs (section 7.2: 6 for the app side). */
    public const APP_CPUS = 6;

    /** Exit code of `ladder.php record` and `ladder.php decision` when the scenario stops after this step. */
    public const EXIT_STOP = 6;

    /**
     * id => [the multiple of N_0 the 8 N tables are set to, the mix of the routes] (section 7.2's table).
     *
     * @var array<string, array{0: int, 1: string}>
     */
    private const SCENARIOS = [
        't-1' => [3, RouteMix::EVEN],
        't-2' => [10, RouteMix::EVEN],
        't-3' => [10, RouteMix::FIRST_80],
        't-4' => [1, RouteMix::EVEN],
        't-5' => [10, RouteMix::LAST_80],
    ];

    /**
     * The order of the measurement (spec: by scale, so the 8 N tables are rebuilt as seldom as possible): N_0, then 3 x N_0,
     * then 10 x N_0.
     *
     * @return list<array{scale: int, scenarios: list<string>}>
     */
    public static function order(): array
    {
        $byScale = [];
        foreach (self::SCENARIOS as $id => [$multiple]) {
            $byScale[$multiple * self::BASE_SCALE][] = $id;
        }
        ksort($byScale);
        $out = [];
        foreach ($byScale as $scale => $ids) {
            $out[] = ['scale' => $scale, 'scenarios' => $ids];
        }

        return $out;
    }

    /**
     * @return array{id: string, scale: int, multiple: int, mix: string}
     *
     * @throws \InvalidArgumentException the id is not one of the prediction scenarios
     */
    public static function scenario(string $id): array
    {
        if (!isset(self::SCENARIOS[$id])) {
            throw new \InvalidArgumentException(sprintf('unknown scenario "%s" (one of %s)', $id, implode(', ', array_keys(self::SCENARIOS))));
        }
        [$multiple, $mix] = self::SCENARIOS[$id];

        return ['id' => $id, 'scale' => $multiple * self::BASE_SCALE, 'multiple' => $multiple, 'mix' => $mix];
    }

    /**
     * Does the scenario stop after this step (section 7.2: the step measured was at 80% or more of the DB, or its rps did not
     * come out), and what is written beside the stop?
     *
     * A step whose rps did not come out while the DB was under 80% is "the app was the bottleneck": the note says so and the
     * record carries the app's CPU utilization next to it. The step that stops the scenario is itself recorded and kept.
     *
     * @return array{stop: bool, reasons: list<string>, note: string|null}
     */
    public static function decide(float $dbUtilization, bool $rpsValid): array
    {
        $reasons = [];
        if ($dbUtilization >= self::STOP_UTILIZATION - 1e-12) {
            $reasons[] = 'db_utilization_at_or_above_80_percent';
        }
        if (!$rpsValid) {
            $reasons[] = 'rps_not_reached';
        }
        $note = null;
        if (!$rpsValid && $dbUtilization < self::STOP_UTILIZATION - 1e-12) {
            $note = 'app_side_stall: the rps did not come out while the DB was under 80%';
        }

        return ['stop' => $reasons !== [], 'reasons' => $reasons, 'note' => $note];
    }

    /**
     * What a step would count in the kept window if the requests of each route arrived evenly spaced from the start of its
     * stream (`start_offset_sec`, 0 if the plan has none), against what was decided. This is arithmetic on the plan, not a
     * measurement: with few requests per route the count is a whole number per route, and that alone can put a step outside the 5%
     * of the rps check.
     *
     * @param array{rps: float|int, duration_sec: int, warmup_sec: int, routes: list<array<string, mixed>>} $plan
     *
     * @return array{expected_requests: int, decided_requests: float, deviation: float, within_5_percent: bool, max_route_count_error: float, min_edge_margin_sec: float}
     */
    public static function arrivalPreview(array $plan): array
    {
        $total = 0;
        $worst = 0.0;
        $margin = INF;
        $window = $plan['duration_sec'] - $plan['warmup_sec'];
        foreach ($plan['routes'] as $r) {
            $spacing = (int) rtrim((string) $r['time_unit'], 's') / (int) $r['rate'];
            $phi = (float) ($r['start_offset_sec'] ?? 0.0);
            $n = LadderShape::countIn($phi, $spacing, $plan['warmup_sec'], $plan['duration_sec']);
            $total += $n;
            $worst = max($worst, abs($n - (float) $r['rps'] * $window));
            $margin = min($margin, LadderShape::distanceToNearest($phi, $spacing, $plan['warmup_sec']), LadderShape::distanceToNearest($phi, $spacing, $plan['duration_sec']));
        }
        $decided = (float) $plan['rps'] * $window;
        $deviation = $total / $decided - 1.0;

        return [
            'expected_requests' => $total,
            'decided_requests' => $decided,
            'deviation' => $deviation,
            'within_5_percent' => abs($deviation) <= RpsAchievement::TOLERANCE + 1e-9,
            'max_route_count_error' => $worst,
            'min_edge_margin_sec' => $margin,
        ];
    }

    /**
     * The record of one step.
     *
     * @param array{scenario: string, scale: int, rung: int, started_at: string, ended_at: string, prediction?: array<string, mixed>} $meta
     * @param array<string, mixed> $summary the output of summarize-run (with --app-samples)
     *
     * @return array<string, mixed>
     */
    public static function record(array $meta, array $summary): array
    {
        $rps = $summary['rps'];
        $db = $summary['db_utilization'];
        $app = $summary['app_cpu_utilization'] ?? null;
        $decision = self::decide((float) $db['utilization'], (bool) $rps['valid']);
        if ($decision['note'] !== null && $app === null) {
            $decision['note'] .= ' (the app CPU utilization was not sampled)';
        }

        return [
            'scenario' => $meta['scenario'],
            'scale' => $meta['scale'],
            'mix' => $summary['mix'],
            'scheme' => $summary['scheme'] ?? LadderShape::SPEC,
            'rung' => $meta['rung'],
            'decided_rps' => $rps['target_rps'],
            'achieved_rps' => $rps['achieved_rps'],
            'rps_ratio' => $rps['ratio'],
            'rps_valid' => $rps['valid'],
            'rps_status' => $rps['status'],
            'db_utilization' => $db['utilization'],
            'db_utilization_percent' => $db['percent'],
            'app_cpu_utilization' => $app === null ? null : $app['utilization'],
            'app_cpu_percent' => $app === null ? null : $app['percent'],
            'k6_dropped_iterations' => $summary['requests']['dropped_iterations'],
            'requests_in_window' => $summary['requests']['in_window'],
            'requests_not_2xx' => $summary['requests']['failed'],
            'http_status' => $summary['requests']['http_status'] ?? null,
            'window_sec' => $summary['window_sec'],
            'window_epoch' => $summary['window_epoch'],
            'started_at' => $meta['started_at'],
            'ended_at' => $meta['ended_at'],
            'decision' => $decision,
            'prediction' => $meta['prediction'] ?? null,
            'per_route' => $summary['per_route'],
        ];
    }

    /**
     * The one-line print at the end of a step.
     *
     * @param array<string, mixed> $record
     */
    public static function endLine(array $record): string
    {
        $status = $record['http_status'] === null ? 'status ?' : implode(' ', array_map(
            static fn ($code, $n): string => $code . 'x' . $n,
            array_keys($record['http_status']),
            $record['http_status'],
        ));

        return sprintf(
            'RUNG END %s rung %d rps decided %s achieved %.4f (%s) db %.1f%% app %s dropped %d | %s | %s',
            $record['scenario'],
            $record['rung'],
            rtrim(rtrim(number_format((float) $record['decided_rps'], 4, '.', ''), '0'), '.'),
            (float) $record['achieved_rps'],
            $record['rps_status'],
            (float) $record['db_utilization_percent'],
            $record['app_cpu_percent'] === null ? 'n/a' : sprintf('%.1f%%', (float) $record['app_cpu_percent']),
            (int) $record['k6_dropped_iterations'],
            $status,
            $record['decision']['stop'] ? 'STOP (' . implode(', ', $record['decision']['reasons']) . ')' : 'continue',
        );
    }
}
