<?php

declare(strict_types=1);

/*
 * Scores peak-sim against the fixed answers of the real-app trials. See README.md in this directory.
 *
 *   php benchmarks/real-apps/score.php --analysis=firefly-iii=path/to/analysis.json [--analysis=koel=...] [options]
 *   php benchmarks/real-apps/score.php --runtime=firefly-iii:R1=path/to/run-dir [--runtime=firefly-iii:R2=...] [options]
 *
 * Reads only. Writes the report to standard output. Exit status: 0 done, 2 usage, 3 an input cannot be read,
 * 4 a clone is not at the commit the answers were decided on.
 */

require_once __DIR__ . '/lib/Truth.php';
require_once __DIR__ . '/lib/StaticScorer.php';
require_once __DIR__ . '/lib/RuntimeScorer.php';

use PeakSim\Benchmarks\RealApps\RuntimeScorer;
use PeakSim\Benchmarks\RealApps\StaticScorer;
use PeakSim\Benchmarks\RealApps\Truth;

exit(scoreMain(array_slice($argv, 1)));

function scoreUsage(): void
{
    fwrite(STDERR, <<<'TXT'
usage: php benchmarks/real-apps/score.php [options]
  --truth=PATH                  the answers (default: truth.json next to this script)
  --analysis=APP=PATH           a static analysis (JSON of `peak-sim analyze --format=json`) of one app; repeat per app
  --runtime=APP:LABEL=DIR       a measurement run (a directory with routes.jsonl and attempts.jsonl); repeat per run
  --unreached-bytes=N           a response size known to be a prompt, not the handler (off by default)
  --clone=APP=PATH              check that the clone is at the commit the answers were decided on
  --allow-clone-mismatch        score anyway when a clone is at another commit (the report says so)
  --app=APP  --set=SET  --axis=query|memory    keep only these (repeat to keep several)
  --rows                        also list every answer that is not an agreement
  --format=text|json            (default text)

TXT);
}

/**
 * @param list<string> $args
 */
function scoreMain(array $args): int
{
    ini_set('memory_limit', '-1'); // the analyses of large apps are big JSON files; this tool only reads them

    $opt = ['truth' => __DIR__ . '/truth.json', 'analysis' => [], 'runtime' => [], 'clone' => [], 'app' => [], 'set' => [], 'axis' => [],
        'unreached' => null, 'allow' => false, 'rows' => false, 'format' => 'text'];
    foreach ($args as $arg) {
        if (!preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $arg, $m)) {
            scoreUsage();

            return 2;
        }
        $name = $m[1];
        $value = $m[2] ?? null;
        switch ($name) {
            case 'truth':
                $opt['truth'] = (string) $value;
                break;
            case 'analysis':
            case 'clone':
                if ($value === null || !str_contains($value, '=')) {
                    scoreUsage();

                    return 2;
                }
                [$app, $path] = explode('=', $value, 2);
                $opt[$name][$app] = $path;
                break;
            case 'runtime':
                if ($value === null || !preg_match('/^([^:=]+):([^=]+)=(.+)$/s', $value, $r)) {
                    scoreUsage();

                    return 2;
                }
                $opt['runtime'][] = ['app' => $r[1], 'label' => $r[2], 'dir' => $r[3]];
                break;
            case 'app':
            case 'set':
            case 'axis':
                $opt[$name][] = (string) $value;
                break;
            case 'unreached-bytes':
                $opt['unreached'] = (int) $value;
                break;
            case 'allow-clone-mismatch':
                $opt['allow'] = true;
                break;
            case 'rows':
                $opt['rows'] = true;
                break;
            case 'format':
                if (!in_array($value, ['text', 'json'], true)) {
                    scoreUsage();

                    return 2;
                }
                $opt['format'] = $value;
                break;
            default:
                scoreUsage();

                return 2;
        }
    }
    if ($opt['analysis'] === [] && $opt['runtime'] === []) {
        scoreUsage();

        return 2;
    }

    try {
        $truth = Truth::load($opt['truth']);
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");

        return 3;
    }

    // the clones must be at the commits the answers were decided on
    $cloneNotes = [];
    foreach ($opt['clone'] as $app => $path) {
        $want = $truth['apps'][$app]['commit'] ?? null;
        $have = $want === null ? null : scoreGitHead($path);
        if ($want === null || $have === null) {
            fwrite(STDERR, "cannot check the clone of {$app} ({$path})\n");

            return 3;
        }
        if ($have !== $want) {
            $cloneNotes[] = "clone of {$app} is at " . substr($have, 0, 12) . ', the answers were decided on ' . substr($want, 0, 12);
        }
    }
    if ($cloneNotes !== [] && !$opt['allow']) {
        fwrite(STDERR, implode("\n", $cloneNotes) . "\n(use --allow-clone-mismatch to score anyway)\n");

        return 4;
    }

    $filter = ['apps' => $opt['app'], 'sets' => $opt['set'], 'axes' => $opt['axis']];
    $report = ['truth' => ['file' => basename($opt['truth']), 'answers' => count($truth['routes'])], 'notes' => $cloneNotes, 'static' => null, 'runtime' => []];

    if ($opt['analysis'] !== []) {
        $analyses = [];
        foreach ($opt['analysis'] as $app => $path) {
            $doc = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            if (!is_array($doc) || !is_array($doc['routes'] ?? null)) {
                fwrite(STDERR, "cannot read the analysis of {$app}: {$path}\n");

                return 3;
            }
            $analyses[$app] = ['routes' => array_map(static fn (array $r): array => [
                'route' => $r['route'] ?? '', 'methods' => $r['methods'] ?? [], 'query' => $r['query'] ?? [], 'memory' => $r['memory'] ?? [],
            ], $doc['routes'])];
            unset($doc);
        }
        $report['static'] = StaticScorer::score($truth, $analyses, $filter);
    }

    foreach ($opt['runtime'] as $run) {
        $routes = scoreJsonl($run['dir'] . '/routes.jsonl');
        $attempts = scoreJsonl($run['dir'] . '/attempts.jsonl');
        if ($routes === null || $attempts === null) {
            fwrite(STDERR, "cannot read routes.jsonl and attempts.jsonl in {$run['dir']}\n");

            return 3;
        }
        $report['runtime'][$run['app'] . ':' . $run['label']] = RuntimeScorer::score($truth, $run['app'], $routes, $attempts, $filter, $opt['unreached']);
    }

    if ($opt['format'] === 'json') {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";

        return 0;
    }
    echo scoreText($report, $opt['rows']);

    return 0;
}

function scoreGitHead(string $path): ?string
{
    $out = [];
    $code = 0;
    exec('git -C ' . escapeshellarg($path) . ' rev-parse HEAD 2>/dev/null', $out, $code);

    return $code === 0 && isset($out[0]) ? trim($out[0]) : null;
}

/**
 * @return list<array<string, mixed>>|null
 */
function scoreJsonl(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $rows = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $row = json_decode($line, true);
        if (!is_array($row)) {
            return null;
        }
        $rows[] = $row;
    }

    return $rows;
}

/**
 * @param array<string, mixed> $report
 */
function scoreText(array $report, bool $withRows): string
{
    $out = "answers: {$report['truth']['answers']} routes in {$report['truth']['file']}\n";
    foreach ($report['notes'] as $note) {
        $out .= "NOTE: {$note}\n";
    }

    if ($report['static'] !== null) {
        $summary = $report['static']['summary'];
        $out .= "\n== static analysis (per answered axis) ==\n";
        $out .= sprintf("%-28s %7s %6s %6s %7s %10s %9s %9s %7s\n", 'group', 'answers', 'scored', 'agree', 'unknown', 'bound_over', 'overclaim', 'missed', 'other');
        foreach ($summary as $key => $s) {
            $c = $s['counts'];
            $other = $s['scored'] - $s['agree'] - $s['unknown'] - $c['bound_over'] - $c['overclaim'] - $s['missed'];
            $out .= sprintf("%-28s %7d %6d %6d %7d %10d %9d %9d %7d\n", $key, $s['answers'], $s['scored'], $s['agree'], $s['unknown'], $c['bound_over'], $c['overclaim'], $s['missed'], $other);
        }
        $out .= "\nall categories (group 'all'):\n";
        foreach ($summary['all']['counts'] as $cat => $n) {
            $out .= sprintf("  %-22s %d\n", $cat, $n);
        }
        $out .= "agree = match + bound_tight + conditional_ok; missed = missed + missed_if_condition; other = bound_loose + bound_on_conditional + unverifiable\n";
        if ($withRows) {
            $out .= "\nanswers that are not an agreement:\n";
            foreach ($report['static']['rows'] as $row) {
                if (!in_array($row['category'], ['match', 'bound_tight', 'conditional_ok'], true)) {
                    $out .= sprintf("  %-20s %-6s truth=%-12s static=%-8s bound=%-7s %s\n", $row['category'], $row['axis'], (string) $row['truth'], (string) $row['static'], (string) $row['lower_bound'], $row['id']);
                }
            }
        }
    }

    foreach ($report['runtime'] as $label => $run) {
        $s = $run['summary'];
        $c = $s['counts'];
        $out .= "\n== runtime: {$label} ==\n";
        $out .= sprintf("routes %d, reached %d; A %d  B %d  C %d  D %d  D_candidate %d  E %d  not_run %d\n", $s['routes'], $s['reached'], $c['A'], $c['B'], $c['C'], $c['D'], $c['D_candidate'], $c['E'], $c['not_run']);
        $out .= sprintf("D = %d: confirmed %d / doubtful %d (D_candidate %d is not counted in D)\n", $c['D'], $s['d_confirmed'], $s['d_doubtful'], $c['D_candidate']);
        $out .= $s['a_over_abc'] === null
            ? "A / (A+B+C): no denominator\n"
            : sprintf("A / (A+B+C) = %d/%d = %.1f%%\n", $c['A'], $s['denominator'], $s['a_over_abc'] * 100);
        foreach ($s['b_reasons'] as $reason => $n) {
            $out .= sprintf("  B because %-60s %d\n", $reason, $n);
        }
        if ($s['c_routes'] !== []) {
            $out .= '  C (false O(1)): ' . implode('; ', $s['c_routes']) . "\n";
        }
        if ($withRows) {
            foreach ($run['rows'] as $row) {
                $out .= sprintf("  %-12s %-5s %s%s\n", $row['class'] . ($row['d_kind'] === null ? '' : ':' . $row['d_kind']), $row['group'], $row['id'], $row['class'] === 'A' ? '' : ' - ' . $row['detail']);
            }
        }
    }

    return $out;
}
