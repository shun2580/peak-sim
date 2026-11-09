<?php

declare(strict_types=1);

/*
 * The PHP half of `poc/lab/bin/measure-ladder`: the steps, the scenarios, the stopping rule and the record of one step. It
 * measures nothing and touches no container.
 *
 *   php ladder.php rungs                          the rps of each step, one per line
 *   php ladder.php scenario <id> [--line]         scale, multiple of N_0 and mix of a prediction scenario (--line: "<scale> <mix>")
 *   php ladder.php order                          the order of the measurement by scale (JSON)
 *   php ladder.php preview <plan.json>            what the plan would count in the kept window if the requests arrived evenly (JSON)
 *   php ladder.php table --dir=<dir of rung-NN-<rps>/plan.json>
 *                                                 the dry-run table: per step the rate of the routes and what the plan would count
 *   php ladder.php record --scenario=<id> --rung=<n> --summary=<summarize-run output> --started=<iso> --ended=<iso>
 *                         [--prediction-file=<path> --prediction-commit=<hash> --prediction-time=<iso>]
 *                                                 the record of one step (JSON on stdout, the end line on stderr)
 *   php ladder.php decision <record.json>         the stopping decision of a record that was written
 *   php ladder.php collect --dir=<scenario dir>   the records of the steps in the directory, in one file (JSON on stdout)
 *
 * exit codes: 0 done (for `record` and `decision`: go on to the next step) / 6 `record` and `decision`: the scenario stops after
 * this step (not an error) / 2 wrong usage or an unreadable input
 */

require __DIR__ . '/../../runtime/bootstrap.php';

use PeakSim\Runtime\LabLoad\Ladder;

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
        foreach (Ladder::RUNGS as $rps) {
            echo rtrim(rtrim(number_format($rps, 4, '.', ''), '0'), '.'), "\n";
        }
        exit(0);

    case 'scenario':
        try {
            $s = Ladder::scenario($positional[0] ?? '');
        } catch (InvalidArgumentException $e) {
            fail($e->getMessage());
        }
        echo isset($opts['line']) ? $s['scale'] . ' ' . $s['mix'] . "\n" : json_encode($s, JSON_FLAGS) . "\n";
        exit(0);

    case 'order':
        echo json_encode(Ladder::order(), JSON_FLAGS), "\n";
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
        printf("%-4s %-6s %-30s %-9s %-9s %s\n", 'step', 'rps', 'route rates (count/time unit)', 'expected', 'decided', 'deviation if the arrivals are evenly spaced');
        foreach ($files as $i => $file) {
            $plan = readJson($file);
            $rates = [];
            foreach ($plan['routes'] as $r) {
                $rates[$r['rate'] . '/' . $r['time_unit']] = ($rates[$r['rate'] . '/' . $r['time_unit']] ?? 0) + 1;
            }
            $label = implode(' ', array_map(static fn (string $k, int $n): string => $n . 'x' . $k, array_keys($rates), $rates));
            $p = Ladder::arrivalPreview($plan);
            printf(
                "%-4d %-6s %-30s %-9d %-9.1f %+.1f%% (%s)\n",
                $i + 1,
                rtrim(rtrim(number_format((float) $plan['rps'], 4, '.', ''), '0'), '.'),
                $label,
                $p['expected_requests'],
                $p['decided_requests'],
                $p['deviation'] * 100,
                $p['within_5_percent'] ? 'within 5%' : 'OUTSIDE 5%',
            );
        }
        exit(0);

    case 'record':
        foreach (['scenario', 'rung', 'summary', 'started', 'ended'] as $need) {
            if (!isset($opts[$need])) {
                fail("record needs --$need");
            }
        }
        try {
            $scenario = Ladder::scenario($opts['scenario']);
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
            'rungs_in_ladder' => count(Ladder::RUNGS),
            'stopped_after_rung' => $last !== null && $last['decision']['stop'] ? $last['rung'] : null,
            'stop_reasons' => $last !== null && $last['decision']['stop'] ? $last['decision']['reasons'] : [],
            'rungs' => $rungs,
        ], JSON_FLAGS), "\n";
        exit(0);

    default:
        fail('usage: ladder.php rungs | scenario <id> [--line] | order | preview <plan.json> | table --dir=<dir> | record ... | decision <record.json> | collect --dir=<dir>');
}
