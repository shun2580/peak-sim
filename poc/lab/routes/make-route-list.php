<?php

declare(strict_types=1);

// Makes the route list of the lab backtest (simulator-spec.md section 7.2) from estimate.json and fits.json. Reads the two files only;
// touches no database and no app. Writes the list as JSON to the standard output.
//
//   php make-route-list.php --estimate=<estimate.json> --fits=<fits.json>
//
// The paths are written into the list as they are given, so give them relative to the repository root.
// exit codes: 0 done / 2 wrong usage or an unreadable or invalid input

require __DIR__ . '/RouteList.php';

use PeakSim\Lab\Routes\RouteList;

$usage = 'usage: make-route-list --estimate=<estimate.json> --fits=<fits.json>';
$given = ['estimate' => null, 'fits' => null];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('~^--(estimate|fits)=(.+)$~', $arg, $m) !== 1) {
        fwrite(STDERR, "make-route-list: unknown argument: $arg\n$usage\n");
        exit(2);
    }
    $given[$m[1]] = $m[2];
}
if ($given['estimate'] === null || $given['fits'] === null) {
    fwrite(STDERR, "$usage\n");
    exit(2);
}

$decoded = [];
$records = [];
foreach ($given as $name => $path) {
    $contents = is_file($path) ? file_get_contents($path) : false;
    if ($contents === false) {
        fwrite(STDERR, "make-route-list: --$name is not a readable file: $path\n");
        exit(2);
    }
    $data = json_decode($contents, true);
    if (!is_array($data)) {
        fwrite(STDERR, "make-route-list: --$name is not valid JSON: $path\n");
        exit(2);
    }
    $decoded[$name] = $data;
    $records[$name] = RouteList::inputRecord($path, $contents);
}

try {
    $list = RouteList::build($decoded['estimate'], $decoded['fits'], $records);
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, 'make-route-list: ' . $e->getMessage() . "\n");
    exit(2);
}

fwrite(STDOUT, json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
exit(0);
