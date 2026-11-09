<?php

declare(strict_types=1);

// Scores the lab backtest from one input file (simulator-spec.md sections 7.2 and 7.4 B) and writes score.json and score.md.
// Reads the one file; touches no database, no app, no clock.
//
//   php score-ladder.php --input=<scoring input .json> --out=<output dir>
//   php score-ladder.php --prediction=<prediction .json> --ladder=<ladder.json> [--ladder=<ladder.json> ...] --out=<output dir>
//
// The second form is the one used for the backtest: the prediction file and the record of each measured scenario (one ladder.json each).
// A scenario of the prediction with no ladder record is listed with every stage not measured.
//
// exit codes: 0 scored (the verdict, even a fail or an indeterminate, is in the output) / 2 wrong usage, an unreadable input or an input
// that is not in the form LadderScore describes / 1 an output cannot be written

require __DIR__ . '/LadderScore.php';
require __DIR__ . '/LadderScoreText.php';
require __DIR__ . '/LadderInput.php';
require __DIR__ . '/LadderMeasurements.php';

use PeakSim\Lab\Score\LadderInput;
use PeakSim\Lab\Score\LadderMeasurements;
use PeakSim\Lab\Score\LadderScore;
use PeakSim\Lab\Score\LadderScoreText;

$usage = 'usage: score-ladder (--input=<scoring input .json> | --prediction=<prediction .json> --ladder=<ladder.json> [--ladder=...]) --out=<output dir>';
$input = '';
$out = '';
$predictionPath = '';
$ladderPaths = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--input=')) {
        $input = substr($arg, strlen('--input='));
    } elseif (str_starts_with($arg, '--prediction=')) {
        $predictionPath = substr($arg, strlen('--prediction='));
    } elseif (str_starts_with($arg, '--ladder=')) {
        $ladderPaths[] = substr($arg, strlen('--ladder='));
    } elseif (str_starts_with($arg, '--out=')) {
        $out = substr($arg, strlen('--out='));
    } else {
        fwrite(STDERR, "score-ladder: unknown argument: $arg\n$usage\n");
        exit(2);
    }
}
$joined = $predictionPath !== '' || $ladderPaths !== [];
if ($out === '' || ($joined ? ($input !== '' || $predictionPath === '' || $ladderPaths === []) : $input === '')) {
    fwrite(STDERR, "$usage\n");
    exit(2);
}
/** @return array<mixed> */
function readJson(string $path): array
{
    $json = is_file($path) ? file_get_contents($path) : false;
    if ($json === false) {
        throw new InvalidArgumentException("cannot read the input: $path");
    }
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new InvalidArgumentException("the input is not an object: $path");
    }

    return $decoded;
}
try {
    if ($joined) {
        $decoded = LadderInput::join(readJson($predictionPath), LadderMeasurements::fromLadders(array_map('readJson', $ladderPaths)));
    } else {
        $decoded = readJson($input);
    }
    $score = LadderScore::score($decoded);
} catch (JsonException | InvalidArgumentException $e) {
    fwrite(STDERR, 'score-ladder: ' . $e->getMessage() . "\n");
    exit(2);
}
if (!is_dir($out) && !@mkdir($out, 0775, true) && !is_dir($out)) {
    fwrite(STDERR, "score-ladder: cannot create the output directory: $out\n");
    exit(1);
}
$ok = file_put_contents(rtrim($out, '/') . '/score.json', json_encode($score, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n") !== false
    && file_put_contents(rtrim($out, '/') . '/score.md', LadderScoreText::render($score)) !== false;
if (!$ok) {
    fwrite(STDERR, "score-ladder: cannot write the output\n");
    exit(1);
}
fwrite(STDOUT, 'score-ladder: verdict ' . $score['verdict'] . ' (usable stages ' . $score['counts']['usable_stages'] . ', limits measured '
    . $score['counts']['scenarios_with_limit'] . ") -> $out\n");
exit(0);
