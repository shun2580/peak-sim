<?php

declare(strict_types=1);

namespace PeakSim\Lab\Scenarios;

/**
 * The scenario file for calibrating on the lab's three calibration runs and nothing else (simulator-spec.md sections 3.4 and 7.2).
 *
 * Each run is one calibration scenario of the lab (cal-1, cal-2, cal-3): its plan (the rate of each route that was asked for) and its
 * record (what the load tool saw: whether the rps came out, and the utilization of the database over the kept window). Both are read as
 * they were written by the load tools; nothing is typed by hand. The scenario file that comes out has calibration scenarios only. It
 * never has a `target` scenario, so `bin/simulate` has nothing to predict and stops after the calibration.
 *
 * A run whose rps did not come out within 5% (`status` other than `valid`) is refused, never used silently (section 7.2).
 */
final class CalibrationScenario
{
    public const SCHEMA_VERSION = '0.1';

    public const DEFAULT_SCALE = 10000;

    /** The tables that are N in the lab: the union of the `n_table` of the eight routes (the lab's database is shared). */
    public const DEFAULT_TABLES = ['entities', 'images', 'references'];

    public const DEFAULT_CORES = 2;

    /**
     * @param list<array{id: string, plan: array<string, mixed>, record: array<string, mixed>, plan_sha256?: string, record_sha256?: string}> $runs
     * @param list<string> $tables the tables that get the scale
     *
     * @throws \InvalidArgumentException
     */
    public static function build(array $runs, int $scale = self::DEFAULT_SCALE, array $tables = self::DEFAULT_TABLES, int $cores = self::DEFAULT_CORES): string
    {
        if ($runs === []) {
            throw new \InvalidArgumentException('no calibration run was given');
        }
        if ($scale < 1) {
            throw new \InvalidArgumentException('the scale must be a positive whole number');
        }
        if ($tables === []) {
            throw new \InvalidArgumentException('no table was given');
        }
        if ($cores < 1) {
            throw new \InvalidArgumentException('the cores must be a positive whole number');
        }

        $ids = [];
        $blocks = [];
        foreach ($runs as $run) {
            $id = $run['id'];
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $id) !== 1) {
                throw new \InvalidArgumentException(sprintf('the scenario id "%s" is not a plain name', $id));
            }
            if (isset($ids[$id])) {
                throw new \InvalidArgumentException(sprintf('the scenario id "%s" is given twice', $id));
            }
            $ids[$id] = true;
            $blocks[] = self::scenario($run, $scale, $tables);
        }

        $lines = [];
        $lines[] = '# Calibration scenarios of the lab backtest, and nothing else (docs/simulator-spec.md sections 3.4 and 7.2).';
        $lines[] = '#';
        $lines[] = '# Made by poc/lab/scenarios/make-calibration-scenario from the plan and the record of each calibration run; nothing in it is typed';
        $lines[] = '# by hand. There is NO `target` scenario: bin/simulate has nothing to predict and stops after the calibration.';
        $lines[] = '# `observed` is the utilization of the database CPU over the kept window of the run (0 to 1).';
        $lines[] = '#';
        $lines[] = '# Inputs (sha256 of the files as read):';
        foreach ($runs as $run) {
            $lines[] = sprintf(
                '#   %s: plan %s, record %s',
                $run['id'],
                $run['plan_sha256'] ?? 'not recorded',
                $run['record_sha256'] ?? 'not recorded',
            );
        }
        $lines[] = 'schema_version: "' . self::SCHEMA_VERSION . '"';
        $lines[] = '';
        $lines[] = 'resources:';
        $lines[] = '  db_cpu:';
        $lines[] = '    cores: ' . $cores;
        $lines[] = '';
        $lines[] = 'scenarios:';
        foreach ($blocks as $i => $block) {
            if ($i > 0) {
                $lines[] = '';
            }
            array_push($lines, ...$block);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array{id: string, plan: array<string, mixed>, record: array<string, mixed>} $run
     * @param list<string> $tables
     *
     * @return list<string>
     */
    private static function scenario(array $run, int $scale, array $tables): array
    {
        $id = $run['id'];
        $status = $run['record']['rps']['status'] ?? null;
        if ($status !== 'valid') {
            throw new \InvalidArgumentException(sprintf(
                'the run %s is not valid (rps status: %s); an invalid run is not used for the calibration',
                $id,
                is_string($status) ? $status : 'missing',
            ));
        }
        $observed = $run['record']['db_utilization']['utilization'] ?? null;
        if (!is_int($observed) && !is_float($observed)) {
            throw new \InvalidArgumentException(sprintf('the record of %s has no db_utilization.utilization', $id));
        }
        if ($observed <= 0 || $observed > 1) {
            throw new \InvalidArgumentException(sprintf('the utilization of %s (%s) is not between 0 (excluded) and 1', $id, self::number((float) $observed)));
        }
        $routes = $run['plan']['routes'] ?? null;
        if (!is_array($routes) || $routes === []) {
            throw new \InvalidArgumentException(sprintf('the plan of %s has no routes', $id));
        }
        $planned = 0.0;
        $rps = [];
        foreach ($routes as $r) {
            $route = $r['route'] ?? null;
            $value = $r['rps'] ?? null;
            if (!is_string($route) || (!is_int($value) && !is_float($value)) || $value < 0) {
                throw new \InvalidArgumentException(sprintf('the plan of %s has a route without a name or a rate', $id));
            }
            if (isset($rps[$route])) {
                throw new \InvalidArgumentException(sprintf('the plan of %s has the route "%s" twice', $id, $route));
            }
            $rps[$route] = (float) $value;
            $planned += (float) $value;
        }
        $given = $run['plan']['rps'] ?? null;
        if ((is_int($given) || is_float($given)) && abs($planned - (float) $given) > 1e-9 * max(1.0, (float) $given)) {
            throw new \InvalidArgumentException(sprintf('the rates of the plan of %s add up to %s, not to its total %s', $id, self::number($planned), self::number((float) $given)));
        }

        $mix = $run['plan']['mix'] ?? null;
        $lines = [];
        $lines[] = '  - id: ' . $id;
        if (is_string($mix) && preg_match('/^[a-z0-9]+$/', $mix) === 1) {
            $lines[] = '    # mix of routes: ' . $mix . ', total ' . self::number($planned) . ' rps';
        }
        $lines[] = '    kind: calibration';
        $counts = [];
        foreach ($tables as $table) {
            $counts[] = $table . ': ' . $scale;
        }
        $lines[] = '    tables: { ' . implode(', ', $counts) . ' }';
        $lines[] = '    rps:';
        foreach ($rps as $route => $value) {
            $lines[] = '      "' . $route . '": ' . self::number($value);
        }
        $lines[] = '    observed: { db_cpu_utilization: ' . self::number((float) $observed) . ' }';

        return $lines;
    }

    /** The shortest text that reads back as the same float, always with a decimal point. */
    private static function number(float $value): string
    {
        $text = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);

        return $text === false ? (string) $value : $text;
    }
}
