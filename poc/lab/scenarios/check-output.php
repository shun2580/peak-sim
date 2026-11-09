<?php

declare(strict_types=1);

// Checks that a `bin/simulate` run on the calibration-only scenario made the calibration and made NO prediction.
//
//   php check-output.php <prediction.json>
//
// exit codes: 0 calibrated and no prediction / 2 wrong usage or unreadable file / 3 a prediction is there, or the calibration is missing
$path = $argv[1] ?? '';
$contents = is_file($path) ? file_get_contents($path) : false;
$data = $contents === false ? null : json_decode($contents, true);
if (!is_array($data)) {
    fwrite(STDERR, "check-output: cannot read a prediction file: $path\n");
    exit(2);
}
$scenarios = $data['scenarios'] ?? null;
if (!is_array($scenarios) || $scenarios !== []) {
    fwrite(STDERR, 'check-output: the output has ' . (is_array($scenarios) ? (string) count($scenarios) : 'no list of') . " prediction(s); this run must make none\n");
    exit(3);
}
if (!isset($data['calibration']['fits']) || !is_array($data['calibration']['fits']) || $data['calibration']['fits'] === []) {
    fwrite(STDERR, "check-output: the output has no calibration\n");
    exit(3);
}
echo 'ok: calibrated (' . (string) ($data['calibration']['method'] ?? '?') . ' on ' . (string) ($data['calibration']['calibration_scenarios'] ?? '?') . " scenarios), 0 predictions\n";
