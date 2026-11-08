<?php

declare(strict_types=1);

// Runs INSIDE the sealed PHP container (bin/explain-at-scale calls it), as the `measure` user, one step at a time.
//
//   --step=plan      --capture=F --tasks=F --scale=N --small-scale=M --out-dir=D [--data=] [--profile=] [--base=N]
//                    Which databases to build and which routes each is asked about (writes plan files under D).
//   --step=prepare   --group-dir=D/g<G> --capture=F --scale=S        ANALYZE TABLE on every table, then STATS_AUTO_RECALC=0
//                    on the tables the statements of the group's routes name.
//   --step=set-state --group-dir=D/g<G> --state=default|low|high --scale=S   STATS_SAMPLE_PAGES on those tables, then ANALYZE TABLE.
//   --step=explain   --group-dir=D/g<G> --capture=F --state=... --scale=S    EXPLAIN FORMAT=JSON of every statement type of the group.
//   --step=drop      --scale=S                                        drops the group's measurement database.
//   --step=finalize  --run-dir=D --capture=F                          writes D/explain.json from the files the steps wrote.
//
// The group's database is named from PEAK_MEASURE_DB_TAG and the scale (MeasureConfig::dbNameForScale); no name is passed in.
// Two doors, never mixed: statements of the capture go through ExplainSender (ExplainGate) only; ANALYZE and ALTER are written in
// StatsStatements. Exit codes: 0 done / 2 usage / 10 refused (the gate, the database name, an input) / 15 another failure.

use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Config\MeasureRefused;
use PeakSim\Runtime\Explain\CaptureFile;
use PeakSim\Runtime\Explain\ExplainDb;
use PeakSim\Runtime\Explain\ExplainPlan;
use PeakSim\Runtime\Explain\ExplainReport;
use PeakSim\Runtime\Explain\ExplainRun;
use PeakSim\Runtime\Explain\ExplainSender;
use PeakSim\Runtime\Explain\PdoExplainTransport;
use PeakSim\Runtime\Explain\PdoStatsDb;
use PeakSim\Runtime\Explain\RelatedTables;
use PeakSim\Runtime\Explain\StatsCheck;
use PeakSim\Runtime\Explain\StatsSnapshot;
use PeakSim\Runtime\Explain\StatsStatements;
use PeakSim\Runtime\Guard\MeasureDb;
use PeakSim\Runtime\Measure\GeneratorPlanner;
use PeakSim\Runtime\Measure\RouteTask;
use PeakSim\Runtime\Measure\SeederPlanner;

require dirname(__DIR__, 2) . '/bootstrap.php';

const USAGE = 2;
const OTHER = 15;

$usage = static function (string $why): never {
    fwrite(STDERR, $why . "\nusage: explain-at-scale.php --step=plan|prepare|set-state|explain|drop|finalize ... (see the header of this file)\n");
    exit(USAGE);
};

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(step|capture|tasks|scale|small-scale|out-dir|data|profile|base|group-dir|state|run-dir)=(.*)$/Ds', $arg, $m) !== 1) {
        $usage('unknown argument: ' . $arg);
    }
    $opts[$m[1]] = $m[2];
}
$step = $opts['step'] ?? '';
$int = static function (string $name) use ($opts, $usage): int {
    if (!isset($opts[$name]) || preg_match('/^[0-9]{1,7}$/D', $opts[$name]) !== 1 || (int) $opts[$name] < 1) {
        $usage('--' . $name . ' must be a positive integer');
    }

    return (int) $opts[$name];
};
$json = static function (string $file) use ($usage): array {
    $text = is_file($file) ? file_get_contents($file) : false;
    $data = $text === false ? null : json_decode($text, true);
    if (!is_array($data)) {
        $usage('not a readable JSON file: ' . $file);
    }

    return $data;
};
$write = static function (string $file, array $data): void {
    file_put_contents($file, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
};
$groupRoutes = static function (string $dir) use ($usage): array {
    $text = is_file($dir . '/plan.routes') ? file_get_contents($dir . '/plan.routes') : false;
    if ($text === false) {
        $usage('no plan.routes in ' . $dir);
    }

    return array_values(array_filter(explode("\n", $text), static fn (string $l): bool => $l !== ''));
};
$groupDir = static function () use ($opts, $usage): string {
    $dir = $opts['group-dir'] ?? '';
    if ($dir === '' || !is_dir($dir)) {
        $usage('--group-dir is not a directory');
    }

    return rtrim($dir, '/');
};
$state = static function () use ($opts, $usage): string {
    $s = $opts['state'] ?? '';
    if (!isset(StatsStatements::SAMPLE_PAGES[$s])) {
        $usage('--state is one of: ' . implode(', ', array_keys(StatsStatements::SAMPLE_PAGES)));
    }

    return $s;
};

try {
    $config = MeasureConfig::fromEnv(getenv());

    switch ($step) {
        case 'plan':
            $capture = $json($opts['capture'] ?? '');
            $tasks = array_map(RouteTask::fromArray(...), $json($opts['tasks'] ?? ''));
            $mode = $opts['data'] ?? (string) ($capture['data'] ?? 'generator');
            if (!in_array($mode, ['generator', 'seeder'], true)) {
                $usage('--data takes generator or seeder');
            }
            $profile = $opts['profile'] ?? ($capture['profile'] ?? null);
            $profile = $profile === 'none' || $profile === '' ? null : $profile;
            if ($profile !== null && preg_match('/^[a-z][a-z0-9_-]{0,30}$/D', (string) $profile) !== 1) {
                $usage('--profile takes the name of a file in profiles/');
            }
            $planner = $mode === 'seeder' ? new SeederPlanner() : new GeneratorPlanner(isset($opts['base']) ? (int) $opts['base'] : GeneratorPlanner::BASE);
            $read = CaptureFile::read($capture);
            $plan = ExplainPlan::build($tasks, $read['routes'], $int('scale'), $int('small-scale'), $planner, $mode, $profile);
            $dir = rtrim($opts['out-dir'] ?? '', '/');
            if ($dir === '' || !is_dir($dir) || !is_writable($dir)) {
                $usage('--out-dir is not a writable directory');
            }
            ExplainPlan::writeFiles($plan, $dir);
            file_put_contents($dir . '/plan.profile', ($profile ?? 'none') . "\n");
            file_put_contents($dir . '/plan.data', $mode . "\n");
            fwrite(STDOUT, sprintf("planned %d databases for %d routes (%d routes not planned)\n", count($plan['groups']), count($read['routes']) - count($plan['unplanned']), count($plan['unplanned'])));
            exit(0);

        case 'prepare':
            $dir = $groupDir();
            $name = $config->dbNameForScale($int('scale'));
            $read = CaptureFile::read($json($opts['capture'] ?? ''));
            $stats = new StatsStatements(new PdoStatsDb(ExplainDb::open($config, $name)));
            $all = $stats->tables();
            $inGroup = array_fill_keys($groupRoutes($dir), true);
            $hints = [];
            foreach ($read['statements'] as $s) {
                if (isset($inGroup[$s['route']]) && $s['sql'] !== null) {
                    array_push($hints, ...RelatedTables::of($s['sql']));
                }
            }
            $relevant = $stats->relevant($hints);
            $analyzed = $stats->analyze($all);
            $stats->switchAutoRecalcOff($relevant);
            $failed = [];
            foreach ($analyzed as $table => $messages) {
                foreach ($messages as $m) {
                    if (strtolower((string) ($m['Msg_type'] ?? '')) === 'error') {
                        $failed[] = $table . ': ' . (string) ($m['Msg_text'] ?? '');
                    }
                }
            }
            $write($dir . '/prepare.json', [
                'database' => $name,
                'tables' => count($all),
                'relevant_tables' => $relevant,
                'analyze_all' => array_map(static fn (array $ms): array => array_map(static fn (array $m): string => (string) ($m['Msg_text'] ?? ''), $ms), $analyzed),
                'analyze_errors' => $failed,
                'auto_recalc_off_on' => $relevant,
                'server' => $stats->settings(),
            ]);
            if ($failed !== []) {
                fwrite(STDERR, 'ANALYZE TABLE reported an error: ' . implode('; ', array_slice($failed, 0, 3)) . "\n");
                exit(OTHER);
            }
            fwrite(STDOUT, sprintf("prepared %s: %d tables analyzed, STATS_AUTO_RECALC=0 on %d\n", $name, count($all), count($relevant)));
            exit(0);

        case 'set-state':
            $dir = $groupDir();
            $which = $state();
            $name = $config->dbNameForScale($int('scale'));
            $prepared = $json($dir . '/prepare.json');
            $stats = new StatsStatements(new PdoStatsDb(ExplainDb::open($config, $name)));
            $relevant = $stats->relevant(array_values(array_map('strval', (array) ($prepared['relevant_tables'] ?? []))));
            $pages = StatsStatements::SAMPLE_PAGES[$which];
            $stats->setSamplePages($relevant, $pages);
            $analyzed = $stats->analyze($relevant);
            $write($dir . '/state_' . $which . '.json', [
                'state' => $which,
                'pages' => $pages,
                'tables' => $relevant,
                'analyze' => array_map(static fn (array $ms): array => array_map(static fn (array $m): string => (string) ($m['Msg_text'] ?? ''), $ms), $analyzed),
            ]);
            fwrite(STDOUT, sprintf("state %s: STATS_SAMPLE_PAGES=%d and ANALYZE TABLE on %d tables\n", $which, $pages, count($relevant)));
            exit(0);

        case 'explain':
            $dir = $groupDir();
            $which = $state();
            $name = $config->dbNameForScale($int('scale'));
            $read = CaptureFile::read($json($opts['capture'] ?? ''));
            $sender = new ExplainSender(new PdoExplainTransport(ExplainDb::open($config, $name)));
            $results = ExplainRun::run($sender, $read['statements'], $groupRoutes($dir));
            $write($dir . '/explain_' . $which . '.json', ['state' => $which, 'database' => $name, 'sent' => $sender->sent(), 'results' => $results]);
            fwrite(STDOUT, sprintf("state %s: %d statement types, %d sent as EXPLAIN FORMAT=JSON\n", $which, count($results), $sender->sent()));
            exit(0);

        case 'drop':
            $name = $config->dbNameForScale($int('scale'));
            MeasureDb::connect($config)->drop($name);
            fwrite(STDOUT, "dropped $name\n");
            exit(0);

        case 'finalize':
            $runDir = rtrim($opts['run-dir'] ?? '', '/');
            if ($runDir === '' || !is_dir($runDir)) {
                $usage('--run-dir is not a directory');
            }
            $capture = $json($opts['capture'] ?? '');
            $read = CaptureFile::read($capture);
            $plan = $json($runDir . '/plan.json');
            $groups = [];
            $stats = [];
            foreach (array_values((array) $plan['groups']) as $g => $group) {
                $gd = $runDir . '/g' . ($g + 1);
                $results = [];
                foreach (array_keys(StatsStatements::SAMPLE_PAGES) as $which) {
                    if (is_file($gd . '/explain_' . $which . '.json')) {
                        $results[$which] = $json($gd . '/explain_' . $which . '.json')['results'];
                    }
                }
                $prepared = is_file($gd . '/prepare.json') ? $json($gd . '/prepare.json') : [];
                $groups[] = ['role' => $group['role'], 'scale' => (int) $group['scale'], 'database' => (string) ($prepared['database'] ?? ''), 'routes' => $group['routes'], 'results' => $results];
                $checks = [];
                $snap = static fn (string $f): ?StatsSnapshot => is_file($f) ? StatsSnapshot::parse((string) file_get_contents($f)) : null;
                foreach (array_keys($results) as $which) {
                    $b = $snap($gd . '/stats_' . $which . '_before.tsv');
                    $a = $snap($gd . '/stats_' . $which . '_after_set.tsv');
                    $e = $snap($gd . '/stats_' . $which . '_after_explain.tsv');
                    $checks[$which] = $b === null || $a === null || $e === null
                        ? ['ok' => false, 'reason' => 'a copy of the statistics is missing']
                        : StatsCheck::state($b, $a, $e, array_values(array_map('strval', (array) ($prepared['relevant_tables'] ?? []))), StatsStatements::SAMPLE_PAGES[$which]);
                }
                $maxByState = [];
                foreach ($checks as $which => $c) {
                    if (isset($c['max_sample_size'])) {
                        $maxByState[$which] = $c['max_sample_size'];
                    }
                }
                $stats['g' . ($g + 1)] = [
                    'role' => $group['role'],
                    'scale' => (int) $group['scale'],
                    'routes' => $group['routes'],
                    'tables' => $prepared['tables'] ?? null,
                    'relevant_tables' => $prepared['relevant_tables'] ?? [],
                    'server' => $prepared['server'] ?? null,
                    'analyze_errors' => $prepared['analyze_errors'] ?? [],
                    'states' => $checks,
                    'ordering' => count($maxByState) === count(StatsStatements::SAMPLE_PAGES) ? StatsCheck::ordering($maxByState) : null,
                ];
            }
            $meta = [
                'scale' => $int('scale'),
                'small_scale' => (int) ($opts['small-scale'] ?? 0),
                'capture' => ['capture_version' => $capture['capture_version'] ?? null, 'app' => $capture['app'] ?? null, 'profile' => $capture['profile'] ?? null, 'data' => $capture['data'] ?? null, 'scales' => $capture['scales'] ?? null],
            ];
            $report = ExplainReport::assemble($meta, $read['statements'], $groups, (array) $plan['unplanned'], $stats);
            $write($runDir . '/explain.json', $report);
            fwrite(STDOUT, sprintf("explain.json: %d statement types, %d plan_unstable, %d plan_changes_at_scale, %d explain_unavailable\n", $report['totals']['statement_types'], $report['totals']['plan_unstable'], $report['totals']['plan_changes_at_scale'], $report['totals']['explain_unavailable']));
            exit(0);

        default:
            $usage('--step is required');
    }
} catch (MeasureRefused $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(MeasureRefused::EXIT_CODE);
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(USAGE);
} catch (\Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 300) . "\n");
    exit(OTHER);
}
