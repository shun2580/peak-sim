<?php

declare(strict_types=1);

// Makes capture.json from a record that `bin/capture` left (attempts.jsonl, summary.json and the directory of statement
// records). Pure computation: no database, no app. Runs inside the container of bin/capture.
//
//   php capture-build.php --from=<the --out directory> --app=<name> [--taps=<dir>] [--to=<file>]
//
// Writes <from>/capture.json (or --to) and prints one line. Exit codes: 0 done / 2 usage / 3 the record cannot be read.

require __DIR__ . '/../../bootstrap.php';

use PeakSim\Runtime\Capture\CaptureBuilder;
use PeakSim\Runtime\Capture\TapDirectory;

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(from|app|taps|to)=(.+)$/D', $arg, $m) !== 1) {
        fwrite(STDERR, "capture-build: unknown argument\n");
        exit(2);
    }
    $opts[$m[1]] = $m[2];
}
if (!isset($opts['from'], $opts['app'])) {
    fwrite(STDERR, "usage: capture-build.php --from=<dir> --app=<name> [--taps=<dir>] [--to=<file>]\n");
    exit(2);
}
$from = rtrim($opts['from'], '/');
$taps = $opts['taps'] ?? $from . '/capture-taps';
$to = $opts['to'] ?? $from . '/capture.json';

$read = static function (string $file): string {
    $text = @file_get_contents($file);
    if ($text === false) {
        fwrite(STDERR, 'capture-build: cannot read ' . basename($file) . "\n");
        exit(3);
    }

    return $text;
};

$attempts = [];
foreach (explode("\n", $read($from . '/attempts.jsonl')) as $line) {
    if (trim($line) === '') {
        continue;
    }
    $row = json_decode($line, true);
    if (!is_array($row)) {
        fwrite(STDERR, "capture-build: attempts.jsonl has a line that is not JSON\n");
        exit(3);
    }
    $attempts[] = $row;
}
$summary = json_decode($read($from . '/summary.json'), true);
$meta = is_array($summary) ? (array) ($summary['meta'] ?? []) : [];
if (!isset($meta['scales']) || !is_array($meta['scales'])) {
    fwrite(STDERR, "capture-build: summary.json has no scales\n");
    exit(3);
}

try {
    $document = CaptureBuilder::build(
        ['app' => $opts['app'], 'profile' => $meta['profile'] ?? null, 'data' => $meta['data'] ?? null, 'scales' => $meta['scales']],
        $attempts,
        TapDirectory::read($taps),
    );
} catch (RuntimeException $e) {
    fwrite(STDERR, 'capture-build: ' . $e->getMessage() . "\n");
    exit(3);
}

$json = json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION) . "\n";
if (file_put_contents($to, $json) === false) {
    fwrite(STDERR, "capture-build: cannot write the output file\n");
    exit(3);
}
$status = [];
$types = 0;
foreach ($document['routes'] as $route) {
    $status[$route['status']] = ($status[$route['status']] ?? 0) + 1;
    $types += count($route['statement_types']);
}
ksort($status);
echo sprintf("capture.json: %d routes (%s), %d statement types\n", count($document['routes']), implode(', ', array_map(static fn (string $k, int $n): string => $n . ' ' . $k, array_keys($status), $status)), $types);
