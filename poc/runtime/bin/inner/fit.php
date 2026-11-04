<?php

declare(strict_types=1);

// Makes the cost formulas (fits.json) from a measurement record. Pure computation: no database, no network, no
// app. Run by bin/fit (here, or in a throwaway container when the machine has no PHP).
//
//   php fit.php --from=<the --out directory of bin/measure> [--source=<name to write as `source`>]
//
// Reads attempts.jsonl and routes.jsonl (and tasks.json when it is there) and prints fits.json on stdout.
// Exit codes: 0 done / 2 usage / 3 the record cannot be read.

require __DIR__ . '/../../bootstrap.php';

use PeakSim\Runtime\Judge\CostFit;

$from = null;
$source = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--from=')) {
        $from = substr($arg, 7);
    } elseif (str_starts_with($arg, '--source=')) {
        $source = substr($arg, 9); // the name of the record, when it is mounted under another one
    } else {
        fwrite(STDERR, "bin/fit: unknown argument\n");
        exit(2);
    }
}
if ($from === null || $from === '') {
    fwrite(STDERR, "usage: bin/fit --from=<the --out directory of bin/measure> > fits.json\n");
    exit(2);
}
$dir = rtrim($from, '/');

$lines = static function (string $file): array {
    $text = @file_get_contents($file);
    if ($text === false) {
        fwrite(STDERR, 'bin/fit: cannot read ' . basename($file) . "\n");
        exit(3);
    }
    $rows = [];
    foreach (explode("\n", $text) as $n => $line) {
        if (trim($line) === '') {
            continue;
        }
        $row = json_decode($line, true);
        if (! is_array($row)) {
            fwrite(STDERR, sprintf("bin/fit: %s line %d is not JSON\n", basename($file), $n + 1));
            exit(3);
        }
        $rows[] = $row;
    }

    return $rows;
};

$attempts = $lines($dir . '/attempts.jsonl');
$routes = $lines($dir . '/routes.jsonl');
$tasks = [];
if (is_file($dir . '/tasks.json')) {
    $decoded = json_decode((string) file_get_contents($dir . '/tasks.json'), true);
    $tasks = is_array($decoded) ? $decoded : [];
}

$fits = CostFit::fromRecords($attempts, $routes, $tasks, $source ?? basename($dir));
echo json_encode($fits, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
