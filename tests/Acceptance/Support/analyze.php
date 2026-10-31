<?php

declare(strict_types=1);

/*
 * Runs the analysis in a process of its own and prints the JSON of the whole report.
 *
 *   php analyze.php <app-root> <export-dir>
 *   php analyze.php <app-root> -- <artisan command...>
 *
 * Used by DeterminismTest: two processes, other environments, other places on disk must print the same bytes.
 */

use PeakSim\Tests\Support\MiniAppRealAnalyzer;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

$appRoot = $argv[1] ?? throw new InvalidArgumentException('app root missing');
if (($argv[2] ?? '') === '--') {
    echo (new MiniAppRealAnalyzer())->json($appRoot, null, ['artisan_command' => implode(' ', array_slice($argv, 3))]);
} else {
    echo (new MiniAppRealAnalyzer())->json($appRoot, $argv[2] ?? throw new InvalidArgumentException('export dir missing'));
}
