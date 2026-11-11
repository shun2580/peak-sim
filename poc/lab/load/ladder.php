<?php

declare(strict_types=1);

/*
 * The PHP half of `poc/lab/bin/measure-ladder`: the steps, the scenarios, the stopping rule and the record of one step. It
 * measures nothing and touches no container.
 *
 *   php ladder.php rungs [--trial]                the rps of each step, one per line (--trial: the one step of a trial, R_0)
 *   php ladder.php scenario <id> [--line]         scale, multiple of N_0 and mix of a prediction scenario (--line: "<scale> <mix>")
 *   php ladder.php scenario <cal-2|cal-3> --trial [--line]   the same for a trial (N_0; a prediction scenario id is refused)
 *   php ladder.php order [--set=<t|r>]            the order of the measurement by scale (JSON; t = t-1..t-5, the default; r = r-1..r-5)
 *   php ladder.php settings --nginx=<file> --fpm=<file> --ini=<file> [--k6-timeout=<duration>]
 *                                                 the three limits of the lab (JSON), worked out from what was read in the containers
 *   php ladder.php shape --scheme=<spec|A|B> --rps=<n>   "<duration> <warmup>" in seconds of a step under the scheme
 *   php ladder.php stagger --scheme=<spec|A|B> <plan.json>   the plan with each route's start time and planned count (JSON)
 *   php ladder.php preview <plan.json>            what the plan would count in the kept window if the requests arrived evenly (JSON)
 *   php ladder.php table --dir=<dir of rung-NN-<rps>/plan.json>
 *                                                 the dry-run table: per step the rate of the routes and what the plan would count
 *   php ladder.php record --scenario=<id>|--trial=<cal-2|cal-3> --rung=<n> --summary=<summarize-run output> --started=<iso> --ended=<iso>
 *                         [--prediction-file (not with --trial)=<path> --prediction-commit=<hash> --prediction-time=<iso>]
 *                         [--settings=<output of `settings`>]
 *                                                 the record of one step (JSON on stdout, the end line on stderr)
 *   php ladder.php decision <record.json>         the stopping decision of a record that was written
 *   php ladder.php collect --dir=<scenario dir>   the records of the steps in the directory, in one file (JSON on stdout)
 *
 * exit codes: 0 done (for `record` and `decision`: go on to the next step) / 6 `record` and `decision`: the scenario stops after
 * this step (not an error) / 2 wrong usage or an unreadable input / 3 `settings`: a limit could not be read from what was collected
 */

require __DIR__ . '/../../runtime/bootstrap.php';

use PeakSim\Runtime\LabLoad\LabSettings;
use PeakSim\Runtime\LabLoad\Ladder;
use PeakSim\Runtime\LabLoad\LadderShape;

const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

function fail(string $message, int $code = 2): never
{
    fwrite(STDERR, "ladder: $message\n");
    exit($code);
}

/** @return array<string, mixed> */
function readJson(string $path): array
{
    $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    if (!is_array($data)) {
        fail("cannot read $path as JSON");
    }

    return $data;
}

$args = array_slice($argv, 1);
$command = array_shift($args) ?? '';
$positional = [];
$opts = [];
foreach ($args as $arg) {
    if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/s', $arg, $m) === 1) {
        $opts[$m[1]] = $m[2] ?? '1';
    } else {
        $positional[] = $arg;
    }
}

switch ($command) {
    case 'rungs':
        // --trial: the one step of a trial (R_0)
        foreach (isset($opts['trial']) ? [Ladder::TRIAL_RPS] : Ladder::RUNGS as $rps) {
            echo rtrim(rtrim(number_format($rps, 4, '.', ''), '0'), '.'), "\n";
        }
        exit(0);

    case 'scenario':
        try {
            $s = isset($opts['trial']) ? Ladder::trial($positional[0] ?? '') : Ladder::scenario($positional[0] ?? '');
        } catch (InvalidArgumentException $e) {
            fail($e->getMessage());
        }
        echo isset($opts['line']) ? $s['scale'] . ' ' . $s['mix'] . "\n" : json_encode($s, JSON_FLAGS) . "\n";
        exit(0);

    case 'order':
        try {
            echo json_encode(Ladder::order($opts['set'] ?? Ladder::SET_FIRST), JSON_FLAGS), "\n";
        } catch (InvalidArgumentException $e) {
            fail($e->getMessage());
        }
        exit(0);

    case 'settings':
        $texts = [];
        foreach (['nginx', 'fpm', 'ini'] as $need) {
            $texts[$need] = isset($opts[$need]) && is_file($opts[$need]) ? (string) file_get_contents($opts[$need]) : null;
            if ($texts[$need] === null) {
                fail("settings needs --$need=<a file that was read from the container>");
            }
        }
        try {
            $settings = LabSettings::fromReadings($texts['nginx'], $texts['fpm'], $texts['ini'], $opts['k6-timeout'] ?? null);
        } catch (InvalidArgumentException $e) {
            fail($e->getMessage(), 3);
        }
        echo json_encode($settings, JSON_FLAGS), "\n";
        exit(0);

    case 'shape':
        try {
            $shape = LadderShape::shape($opts['scheme'] ?? LadderShape::SPEC, (float) ($opts['rps'] ?? 0));
        } catch (InvalidArgumentException $e) {
            fail($e->getMessage());
        }
        echo $shape['duration_sec'] . ' ' . $shape['warmup_sec'] . "\n";
        exit(0);

    case 'stagger':
        try {
            $shaped = LadderShape::stagger(readJson($positional[0] ?? ''), $opts['scheme'] ?? LadderShape::SPEC);
        } catch (InvalidArgumentException $e) {
            fail($e->getMessage());
        }
        echo json_encode($shaped, JSON_FLAGS), "\n";
        exit(0);

    case 'preview':
        $plan = readJson($positional[0] ?? '');
        echo json_encode(Ladder::arrivalPreview($plan), JSON_FLAGS), "\n";
        exit(0);

    case 'table':
        $dir = $opts['dir'] ?? '';
        $files = is_dir($dir) ? (glob(rtrim($dir, '/') . '/rung-*/plan.json') ?: []) : [];
        if ($files === []) {
            fail('table needs --dir=<dir with rung-*/plan.json>');
        }
        sort($files, SORT_STRING);
        printf("%-4s %-6s %-8s %-30s %-9s %-9s %-24s %-10s %s\n", 'step', 'rps', 'window', 'route rates (count/time unit)', 'expected', 'decided', 'deviation (even arrival)', 'route err', 'min edge margin');
        $minutes = 0.0;
        foreach ($files as $i => $file) {
            $plan = readJson($file);
            $rates = [];
            foreach ($plan['routes'] as $r) {
                $rates[$r['rate'] . '/' . $r['time_unit']] = ($rates[$r['rate'] . '/' . $r['time_unit']] ?? 0) + 1;
            }
            $label = implode(' ', array_map(static fn (string $k, int $n): string => $n . 'x' . $k, array_keys($rates), $rates));
            $p = Ladder::arrivalPreview($plan);
            $minutes += $plan['duration_sec'] / 60;
            printf(
                "%-4d %-6s %-8s %-30s %-9d %-9.1f %+6.1f%% %-15s %-10s %s\n",
                $i + 1,
                rtrim(rtrim(number_format((float) $plan['rps'], 4, '.', ''), '0'), '.'),
                ($plan['duration_sec'] - $plan['warmup_sec']) . 's',
                $label,
                $p['expected_requests'],
                $p['decided_requests'],
                $p['deviation'] * 100,
                $p['within_5_percent'] ? 'within 5%' : 'OUTSIDE 5%',
                sprintf('%.2f', $p['max_route_count_error']),
                sprintf('%.2fs', $p['min_edge_margin_sec']),
            );
        }
        printf("all %d steps: %.0f minutes of load\n", count($files), $minutes);
        exit(0);

    case 'record':
        foreach (['rung', 'summary', 'started', 'ended'] as $need) {
            if (!isset($opts[$need])) {
                fail("record needs --$need");
            }
        }
        // a prediction scenario (--scenario) or a trial (--trial), never both
        if (isset($opts['scenario']) === isset($opts['trial'])) {
            fail('record needs --scenario=<t-1..t-5|r-1..r-5> or --trial=<cal-2|cal-3>, and not both');
        }
        if (isset($opts['trial']) && isset($opts['prediction-file'])) {
            fail('a trial has no prediction: --prediction-file is refused with --trial');
        }
        try {
            $scenario = isset($opts['trial']) ? Ladder::trial($opts['trial']) : Ladder::scenario($opts['scenario']);
        } catch (InvalidArgumentException $e) {
            fail($e->getMessage());
        }
        $meta = [
            'scenario' => $scenario['id'],
            'scale' => $scenario['scale'],
            'rung' => (int) $opts['rung'],
            'started_at' => $opts['started'],
            'ended_at' => $opts['ended'],
        ];
        if (isset($opts['trial'])) {
            $meta['trial'] = true;
        }
        if (isset($opts['settings'])) {
            $meta['settings'] = readJson($opts['settings']);
        }
        if (isset($opts['prediction-file'])) {
            $meta['prediction'] = [
                'file' => $opts['prediction-file'],
                'commit' => $opts['prediction-commit'] ?? null,
                'commit_time' => $opts['prediction-time'] ?? null,
            ];
        }
        $record = Ladder::record($meta, readJson($opts['summary']));
        echo json_encode($record, JSON_FLAGS), "\n";
        fwrite(STDERR, Ladder::endLine($record) . "\n");
        exit($record['decision']['stop'] ? Ladder::EXIT_STOP : 0);

    case 'decision':
        $record = readJson($positional[0] ?? '');
        $stop = $record['decision']['stop'] ?? null;
        if (!is_bool($stop)) {
            fail('the record has no decision');
        }
        echo $stop ? 'stop: ' . implode(', ', $record['decision']['reasons']) . "\n" : "continue\n";
        exit($stop ? Ladder::EXIT_STOP : 0);

    case 'collect':
        $dir = $opts['dir'] ?? '';
        if (!is_dir($dir)) {
            fail('collect needs --dir=<scenario dir>');
        }
        $files = glob(rtrim($dir, '/') . '/rung-*/record.json') ?: [];
        sort($files, SORT_STRING);
        $rungs = array_map(static fn (string $f): array => readJson($f), $files);
        usort($rungs, static fn (array $a, array $b): int => $a['rung'] <=> $b['rung']);
        $last = $rungs === [] ? null : $rungs[count($rungs) - 1];
        echo json_encode([
            'scenario' => $rungs[0]['scenario'] ?? null,
            'scale' => $rungs[0]['scale'] ?? null,
            'mix' => $rungs[0]['mix'] ?? null,
            'rungs_measured' => count($rungs),
            'trial' => (bool) ($rungs[0]['trial'] ?? false),
            'rungs_in_ladder' => ($rungs[0]['trial'] ?? false) ? 1 : count(Ladder::RUNGS),
            'stopped_after_rung' => $last !== null && $last['decision']['stop'] ? $last['rung'] : null,
            'stop_reasons' => $last !== null && $last['decision']['stop'] ? $last['decision']['reasons'] : [],
            'rungs' => $rungs,
        ], JSON_FLAGS), "\n";
        exit(0);

    default:
        fail('usage: ladder.php rungs | scenario <id> [--trial] [--line] | order [--set=t|r] | settings --nginx=<f> --fpm=<f> --ini=<f> | preview <plan.json> | shape | stagger | table --dir=<dir> | record ... | decision <record.json> | collect --dir=<dir>');
}
