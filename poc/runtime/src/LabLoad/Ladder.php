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

    /**
     * The steps of the third backtest's scenarios (section 7.4.2): the 13 steps above with 0.025 and 0.035 put under the lowest, 15 in all.
     * A separate list, so that the 13 of t-1 .. t-5 and r-1 .. r-5 (and their records and tables) stay what they were.
     */
    public const RUNGS_S = [0.025, 0.035, 0.05, 0.07, 0.1, 0.15, 0.2, 0.3, 0.45, 0.7, 1.0, 1.5, 2.2, 3.3, 5.0];

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
     * The prediction scenarios of the re-check (section 7.4.1): the same N_0, the same mixes, with the count at 5 x N_0 and 20 x N_0.
     * They are a second table, not more rows of the first, so that the order and the records of t-1 .. t-5 stay what they were.
     *
     * @var array<string, array{0: int, 1: string}>
     */
    private const RECHECK_SCENARIOS = [
        'r-1' => [5, RouteMix::EVEN],
        'r-2' => [5, RouteMix::FIRST_80],
        'r-3' => [20, RouteMix::EVEN],
        'r-4' => [20, RouteMix::FIRST_80],
        'r-5' => [20, RouteMix::LAST_80],
    ];

    /**
     * The prediction scenarios of the third backtest (section 7.4.2): the same N_0 and mixes as the cal-3 definition, with the count at
     * 30 x N_0 = 300,000. s-5 is the one mix no earlier scenario used: 80% on the routes at the even places of the list. A third table,
     * so that the order and the records of t-* and r-* stay what they were.
     *
     * @var array<string, array{0: int, 1: string}>
     */
    private const THIRD_SCENARIOS = [
        's-1' => [30, RouteMix::EVEN],
        's-2' => [30, RouteMix::FIRST_80],
        's-3' => [30, RouteMix::LAST_80],
        's-4' => [30, RouteMix::ODD_80],
        's-5' => [30, RouteMix::EVEN_80],
    ];

    /** The scenario sets: `t` (the first backtest, section 7.2), `r` (the re-check, section 7.4.1) and `s` (the third, section 7.4.2). */
    public const SET_FIRST = 't';
    public const SET_RECHECK = 'r';
    public const SET_THIRD = 's';

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    private static function table(string $set): array
    {
        return match ($set) {
            self::SET_FIRST => self::SCENARIOS,
            self::SET_RECHECK => self::RECHECK_SCENARIOS,
            self::SET_THIRD => self::THIRD_SCENARIOS,
            default => throw new \InvalidArgumentException(sprintf('unknown scenario set "%s" (t, r or s)', $set)),
        };
    }

    /**
     * The steps of a prediction scenario's ladder: the 15 of section 7.4.2 for s-1 .. s-5, the 13 of section 7.2 for every other id
     * (an unknown id is refused by {@see scenario}, not here).
     *
     * @return list<float>
     */
    public static function rungsFor(string $scenarioId): array
    {
        return isset(self::THIRD_SCENARIOS[$scenarioId]) ? self::RUNGS_S : self::RUNGS;
    }

    /**
     * The order of the measurement (spec: by scale, so the 8 N tables are rebuilt as seldom as possible). Set `t`: N_0, then
     * 3 x N_0, then 10 x N_0. Set `r`: 5 x N_0, then 20 x N_0. Set `s`: 30 x N_0 (one scale).
     *
     * @return list<array{scale: int, scenarios: list<string>}>
     */
    public static function order(string $set = self::SET_FIRST): array
    {
        $byScale = [];
        foreach (self::table($set) as $id => [$multiple]) {
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
        $all = self::SCENARIOS + self::RECHECK_SCENARIOS + self::THIRD_SCENARIOS;
        if (!isset($all[$id])) {
            throw new \InvalidArgumentException(sprintf('unknown scenario "%s" (one of %s)', $id, implode(', ', array_keys($all))));
        }
        [$multiple, $mix] = $all[$id];

        return ['id' => $id, 'scale' => $multiple * self::BASE_SCALE, 'multiple' => $multiple, 'mix' => $mix];
    }

    /**
     * The trial scenarios: a dress rehearsal of the measuring tool on the calibration mixes (never on a prediction scenario).
     * name => the mix. The count is N_0 and the one step is R_0 = 1 rps, both fixed here. `even` is not offered: at N_0 it is
     * the prediction scenario t-4, and the trial must not measure it before the prediction is committed.
     *
     * @var array<string, string>
     */
    private const TRIALS = [
        'cal-2' => RouteMix::FIRST_80,
        'cal-3' => RouteMix::ODD_80,
    ];

    /** The one step of a trial: R_0 (section 7.2's calibration rps). */
    public const TRIAL_RPS = 1.0;

    /**
     * @return array{id: string, scale: int, multiple: int, mix: string, trial: true}
     *
     * @throws \InvalidArgumentException the name is not a trial (a prediction scenario id such as t-4 is refused)
     */
    public static function trial(string $name): array
    {
        if (!isset(self::TRIALS[$name])) {
            throw new \InvalidArgumentException(sprintf('unknown trial "%s" (one of %s; a prediction scenario is never a trial)', $name, implode(', ', array_keys(self::TRIALS))));
        }

        return ['id' => 'trial-' . $name, 'scale' => self::BASE_SCALE, 'multiple' => 1, 'mix' => self::TRIALS[$name], 'trial' => true];
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
     * @param array{scenario: string, scale: int, rung: int, started_at: string, ended_at: string, trial?: bool, prediction?: array<string, mixed>, settings?: array<string, mixed>} $meta
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
            // the same by route (which route was cut): null for a run made before the load tool counted it
            'http_status_by_route' => $summary['requests']['http_status_by_route'] ?? null,
            'window_sec' => $summary['window_sec'],
            'window_epoch' => $summary['window_epoch'],
            'started_at' => $meta['started_at'],
            'ended_at' => $meta['ended_at'],
            'decision' => $decision,
            'trial' => $meta['trial'] ?? false,
            'prediction' => $meta['prediction'] ?? null,
            // the three limits at the time of the step (section 7.4.1); null for a step recorded without them (the first backtest)
            'settings' => self::settings($meta['settings'] ?? null, $summary['requests']['request_timeout'] ?? null),
            'per_route' => $summary['per_route'],
        ];
    }

    /**
     * The settings written into a record. The load tool also writes down the timeout it was given (`seen_by_k6`), beside the one the
     * measuring tool says it passed, so that the two can be compared afterwards.
     *
     * @param array<string, mixed>|null $settings
     *
     * @return array<string, mixed>|null
     */
    private static function settings(?array $settings, mixed $seenByK6): ?array
    {
        if ($settings === null) {
            return null;
        }

        return $settings + ['k6_request_timeout_seen_by_k6' => $seenByK6];
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
