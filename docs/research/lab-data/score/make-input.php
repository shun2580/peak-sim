<?php
declare(strict_types=1);
// Builds the scoring input: prediction file + the ladder.json of each scenario, joined by LadderInput::join (adds no number).
require '/score/LadderInput.php';
require '/score/LadderMeasurements.php';
use PeakSim\Lab\Score\LadderInput;
use PeakSim\Lab\Score\LadderMeasurements;
$read = static fn(string $p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$ladders = [];
foreach (['t-1-30000','t-2-100000','t-3-100000','t-4-10000','t-5-100000'] as $d) { $ladders[] = $read("/data/ladder/$d/ladder.json"); }
$in = LadderInput::join($read('/pred/ladder-prediction.json'), LadderMeasurements::fromLadders($ladders));
file_put_contents('/output/score-input.json', json_encode($in, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo "ok\n";
