<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Simulate\Ladder;

use PeakSim\Runtime\Backtest\NaiveEstimate;
use PeakSim\Runtime\Simulate\Calibrate\Calibrator;
use PeakSim\Runtime\Simulate\Calibrate\CalibrationResult;
use PeakSim\Runtime\Simulate\Ladder\LadderPrediction;
use PeakSim\Runtime\Simulate\Ladder\LadderStage;
use PeakSim\Runtime\Simulate\Load\TwoStage;
use PeakSim\Runtime\Simulate\Predict\LimitSearch;
use PeakSim\Runtime\Simulate\Predict\PredictStage;
use PeakSim\Runtime\Simulate\Predict\ScenarioPredictor;
use PeakSim\Runtime\Simulate\SimulateCommand;
use PeakSim\Runtime\Tests\Unit\Simulate\Load\Fixtures;
use PeakSim\Runtime\Tests\Unit\Simulate\Load\RecordingIo;
use PeakSim\Runtime\Tests\Unit\Simulate\Predict\PredictFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The ladder prediction of the lab backtest on the made-up estimate of the first stage's tests (two calibration-shaped routes, one marked).
 * The three tests the task names are here: the solved limit gives exactly the threshold, it agrees with a search in steps of 0.1 within one
 * step, and the utilisation of a stage is byte for byte what `bin/simulate` gives for a scenario file that writes the same rps.
 */
final class LadderPredictionTest extends TestCase
{
    private const CALIBRATIONS = [
        ['cal-1', '{ "GET /a": 5.0, "POST /m": 5.0 }', '0.20'],
        ['cal-2', '{ "GET /a": 8.0, "POST /m": 2.0 }', '0.27'],
        ['cal-3', '{ "GET /a": 2.0, "POST /m": 8.0 }', '0.14'],
    ];

    /** @var array<string, string> id => rps map as YAML text (a target scenario of the made-up file) */
    private const TARGETS = [
        't-1' => '{ "GET /a": 6.0, "POST /m": 2.0, "GET /axisless": 2.0 }',
        't-2' => '{ "GET /a": 2.0, "POST /m": 8.0 }',
    ];

    private const TARGET_TABLES = ['t-1' => '{ pages: 3000, books: 900 }', 't-2' => '{ pages: 10000, books: 3000 }'];

    /** @param array<string, string> $targets id => rps map as YAML text */
    private static function yaml(array $targets = self::TARGETS): string
    {
        $y = "schema_version: \"0.1\"\nresources:\n  db_cpu:\n    cores: 2\n    threshold: 0.8\n  php_memory_limit: 128M\nscenarios:\n";
        foreach (self::CALIBRATIONS as [$id, $rps, $observed]) {
            $y .= "  - id: {$id}\n    kind: calibration\n    tables: { pages: 1000, books: 300 }\n    rps: {$rps}\n    observed: { db_cpu_utilization: {$observed} }\n";
        }
        foreach ($targets as $id => $rps) {
            $y .= "  - id: {$id}\n    kind: target\n    tables: " . self::TARGET_TABLES[$id] . "\n    rps: {$rps}\n";
        }

        return $y;
    }

    /** @return array{0: array<string, mixed>, 1: CalibrationResult} the loaded input and the calibration, as the step computes them */
    private static function prepared(): array
    {
        $io = Fixtures::io(self::yaml());
        $loaded = (new TwoStage($io))->stageOne('/est', Fixtures::SCENARIO_PATH, '/out')->toArray();

        return [$loaded, (new Calibrator())->calibrate(PredictStage::points($loaded))];
    }

    /** @return array{int, string, string, RecordingIo} */
    private static function ladder(string $yaml, ?string $commit = 'abc123'): array
    {
        $io = Fixtures::io($yaml);
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $next = new LadderStage('/est', Fixtures::SCENARIO_PATH, $out, $err, $commit, $commit === null ? null : 'argument', $io);
        $code = (new SimulateCommand($out, $err, $io, $next))->run(['--estimate=/est', '--scenario=' . Fixtures::SCENARIO_PATH, '--out=/out']);
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err), $io];
    }

    /** @return array<string, mixed> */
    private static function ladderReport(string $yaml): array
    {
        [$code, , $stderr, $io] = self::ladder($yaml);
        self::assertSame(0, $code, $stderr);

        return json_decode($io->files['/out/ladder-prediction.json'], true, 512, JSON_THROW_ON_ERROR);
    }

    /** What bin/simulate (the loading step and PredictStage) gives for a scenario file. @return array<string, mixed> */
    private static function simulate(string $yaml): array
    {
        $io = Fixtures::io($yaml);
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $next = new PredictStage('/est', $out, $err, null, null, $io);
        $code = (new SimulateCommand($out, $err, $io, $next))->run(['--estimate=/est', '--scenario=' . Fixtures::SCENARIO_PATH, '--out=/out']);
        self::assertSame(0, $code);

        return json_decode($io->files['/out/prediction.json'], true, 512, JSON_THROW_ON_ERROR);
    }

    private static function number(float $v): string
    {
        return (string) json_encode($v, JSON_PRESERVE_ZERO_FRACTION);
    }

    public function testItWritesAllThirteenStagesOfEveryTargetScenarioAndSaysWhereItCameFrom(): void
    {
        [$code, $stdout, $stderr, $io] = self::ladder(self::yaml());
        self::assertSame(0, $code, $stderr);
        $writes = array_values(array_map(static fn (string $e): string => substr($e, 6), array_filter($io->events, static fn (string $e): bool => str_starts_with($e, 'write '))));
        self::assertSame(['/out/loaded.json', '/out/ladder-prediction.json'], $writes);
        $json = $io->files['/out/ladder-prediction.json'];
        $r = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('lab-ladder-prediction', $r['kind']);
        self::assertSame([0.05, 0.07, 0.1, 0.15, 0.2, 0.3, 0.45, 0.7, 1.0, 1.5, 2.2, 3.3, 5.0], $r['stages_rps_total']);
        self::assertSame(['t-1', 't-2'], array_column($r['scenarios'], 'id'));
        foreach ($r['scenarios'] as $s) {
            self::assertSame($r['stages_rps_total'], array_column($s['stages'], 'rps_total'));
            foreach ($s['stages'] as $stage) {
                self::assertEqualsWithDelta($stage['rps_total'], array_sum($stage['rps']), 1e-12);
                self::assertGreaterThanOrEqual($stage['utilization']['point'], $stage['utilization']['upper']);
            }
            self::assertSame(['point', 'safe', 'naive'], array_keys($s['limit_rps']));
        }
        self::assertSame(['POST /m'], $r['scenarios'][0]['marked_routes']);
        self::assertSame('abc123', $r['inputs']['estimate_commit']);
        self::assertSame('argument', $r['inputs']['estimate_commit_source']);
        self::assertSame(hash('sha256', Fixtures::estimateJson()), $r['inputs']['estimate_sha256']);
        self::assertSame(hash('sha256', self::yaml()), $r['inputs']['scenario_sha256']);
        self::assertArrayHasKey('c_q', $r['calibration']['coefficients']);
        self::assertArrayHasKey('c_row', $r['calibration']['coefficients']);
        self::assertStringContainsString('13 stage(s) x 2 scenario(s)', $stdout);
    }

    public function testTheSameInputGivesTheSameBytes(): void
    {
        self::assertSame(self::ladder(self::yaml())[3]->files['/out/ladder-prediction.json'], self::ladder(self::yaml())[3]->files['/out/ladder-prediction.json']);
    }

    public function testAnUnknownCommitIsWrittenAsNullWithNoSource(): void
    {
        [$code, , , $io] = self::ladder(self::yaml(), null);
        self::assertSame(0, $code);
        $r = json_decode($io->files['/out/ladder-prediction.json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertNull($r['inputs']['estimate_commit']);
        self::assertNull($r['inputs']['estimate_commit_source']);
    }

    public function testTheNaiveEstimateScalesTheCalibrationScenarioWithTheLargestObservedUtilisation(): void
    {
        $r = self::ladderReport(self::yaml());
        // the three calibration scenarios all add up to 10 rps; cal-2 has the largest observed utilisation (0.27)
        self::assertSame('cal-2', $r['naive']['basis_scenario']);
        foreach ($r['scenarios'] as $s) {
            foreach ($s['stages'] as $stage) {
                self::assertEqualsWithDelta(0.27 * $stage['rps_total'] / 10.0, $stage['naive'], 1e-12);
            }
            // threshold 0.8: 0.8 x 10 / 0.27 rps
            self::assertEqualsWithDelta(0.8 * 10.0 / 0.27, $s['limit_rps']['naive']['rps'], 1e-9);
        }
    }

    /** Test 1: run the same part at the solved limit rps and the utilisation is the threshold, for the point, the safe side and the naive estimate. */
    public function testTheSolvedLimitRpsGivesExactlyTheThreshold(): void
    {
        [$loaded, $calibration] = self::prepared();
        $report = self::ladderReport(self::yaml());
        $cores = (float) $loaded['resources']['cores'];
        $basis = [];
        foreach ($loaded['scenarios'] as $s) {
            if ($s['kind'] === 'calibration') {
                $basis[] = ['id' => $s['id'], 'rps_total' => array_sum(array_column($s['routes'], 'rps')), 'utilization' => $s['observed']['db_cpu_utilization']];
            }
        }
        $checked = 0;
        foreach ($loaded['scenarios'] as $s) {
            if ($s['kind'] !== 'target') {
                continue;
            }
            $limits = array_column($report['scenarios'], 'limit_rps', 'id')[$s['id']];
            foreach (['point', 'safe'] as $reading) {
                self::assertSame('found', $limits[$reading]['status']);
                $at = LadderPrediction::scenarioAt($s, $limits[$reading]['rps']);
                $u = ScenarioPredictor::predict($at, $calibration, $cores, 0.8)['utilization'][$reading === 'point' ? 'point' : 'upper'];
                self::assertEqualsWithDelta(0.8, $u, 0.8 * 1e-12, $s['id'] . ' ' . $reading);
                $checked++;
            }
            self::assertEqualsWithDelta(0.8, NaiveEstimate::estimate($basis, $limits['naive']['rps'])['utilization'], 0.8 * 1e-12);
            $checked++;
        }
        self::assertSame(6, $checked);
    }

    /** @return iterable<string, array{float, float, list<array<string, mixed>>}> cQ, cRow, routes of a made-up scenario */
    public static function madeUpScenarios(): iterable
    {
        yield 'a limit between two steps' => [0.01, 0.002, [PredictFixtures::route('GET /a', 2.0, 3.0, 100.0, 400.0), PredictFixtures::route('GET /b', 1.0, 2.0, 50.0)]];
        // L = 1, cores 2: utilisation 0.5, limit 1.6 x the rps, which is exactly a step of the search (the search says 1.7: not over at 1.6)
        yield 'a limit exactly on a step' => [0.0, 0.25, [PredictFixtures::route('GET /a', 1.0, 0.0, 4.0)]];
        // L = 0.1625, utilisation 0.08125: the limit is 9.85 times the rps, one step below the cap of 10
        yield 'a limit near the cap' => [0.0, 0.25, [PredictFixtures::route('GET /a', 0.1, 0.0, 6.5)]];
    }

    /**
     * Test 2: on a made-up scenario, the limit solved from the proportional form agrees with the limit found by running the same part at
     * 0.1, 0.2, ... times the rps, to within one step.
     *
     * @param list<array<string, mixed>> $routes
     */
    #[DataProvider('madeUpScenarios')]
    public function testTheSolvedLimitAgreesWithASearchInStepsOfOneTenthWithinOneStep(float $cQ, float $cRow, array $routes): void
    {
        $calibration = PredictFixtures::twoCoefficient($cQ, $cRow);
        $scenario = ['id' => 'made-up', 'tables' => [], 'routes' => $routes];
        $own = array_sum(array_column($routes, 'rps'));
        $point = ScenarioPredictor::predict($scenario, $calibration, 2.0, 0.8)['utilization']['point'];
        $limit = LadderPrediction::limitRps($point, $own, 0.8);
        self::assertSame('found', $limit['status']);

        $firstOver = null;
        for ($k = 1; $k <= LimitSearch::STEPS; $k++) {
            $at = LadderPrediction::scenarioAt($scenario, $own * $k / LimitSearch::PER_UNIT);
            if (LimitSearch::exceeds(ScenarioPredictor::predict($at, $calibration, 2.0, 0.8)['utilization']['point'], 0.8)) {
                $firstOver = $k / LimitSearch::PER_UNIT;
                break;
            }
        }
        self::assertNotNull($firstOver, 'the limit is not within 10 times the rps in this made-up scenario');
        self::assertEqualsWithDelta($firstOver, $limit['rps'] / $own, 1 / LimitSearch::PER_UNIT + 1e-9);
    }

    public function testAScenarioWithNoCostedRouteHasNoLimit(): void
    {
        self::assertSame(['status' => 'no_load', 'rps' => null], LadderPrediction::limitRps(0.0, 1.0, 0.8));
    }

    public function testTheMixIsKeptAndTheScenarioItselfIsNotChanged(): void
    {
        $scenario = ['id' => 'x', 'routes' => [PredictFixtures::route('GET /a', 3.0, 1.0, 1.0), PredictFixtures::route('GET /b', 1.0, 1.0, 1.0)]];
        $at = LadderPrediction::scenarioAt($scenario, 2.0);
        self::assertEqualsWithDelta(1.5, $at['routes'][0]['rps'], 1e-12);
        self::assertEqualsWithDelta(0.5, $at['routes'][1]['rps'], 1e-12);
        self::assertSame(3.0, $scenario['routes'][0]['rps']);
    }

    /**
     * Test 3: a stage's utilisation (point and safe side) is byte for byte what bin/simulate gives for a scenario file that writes the same
     * rps, for every stage of every target scenario.
     */
    public function testAStagesUtilisationIsTheSameBytesAsBinSimulateOnAScenarioFileWithTheSameRps(): void
    {
        $report = self::ladderReport(self::yaml());
        $compared = 0;
        for ($i = 0; $i < count($report['stages_rps_total']); $i++) {
            $targets = [];
            foreach ($report['scenarios'] as $s) {
                $rps = [];
                foreach ($s['stages'][$i]['rps'] as $route => $value) {
                    $rps[] = '"' . $route . '": ' . self::number($value);
                }
                $targets[$s['id']] = '{ ' . implode(', ', $rps) . ' }';
            }
            $simulated = array_column(self::simulate(self::yaml($targets))['scenarios'], 'utilization', 'id');
            foreach ($report['scenarios'] as $s) {
                $stage = $s['stages'][$i];
                self::assertSame(self::number($simulated[$s['id']]['point']), self::number($stage['utilization']['point']), $s['id'] . ' point at ' . $stage['rps_total']);
                self::assertSame(self::number($simulated[$s['id']]['upper']), self::number($stage['utilization']['upper']), $s['id'] . ' upper at ' . $stage['rps_total']);
                $compared++;
            }
        }
        self::assertSame(26, $compared);
    }

    /**
     * The example of the file's form that the scoring tool reads as its fixture: it is what the step writes for the made-up scenario file of
     * this test, so the form it shows cannot drift from the code.
     */
    public function testTheExampleFileIsWhatTheStepWritesForTheMadeUpScenarioFile(): void
    {
        $written = self::ladder(self::yaml())[3]->files['/out/ladder-prediction.json'];
        self::assertSame((string) file_get_contents(__DIR__ . '/ladder-prediction.example.json'), $written);
    }

    public function testAFileWithNoTargetScenarioStopsWithItsOwnExitCodeAndWritesNoPrediction(): void
    {
        [$code, , $stderr, $io] = self::ladder(self::yaml([]));
        self::assertSame(32, $code);
        self::assertStringContainsString('no target scenario', $stderr);
        self::assertArrayNotHasKey('/out/ladder-prediction.json', $io->files);
    }
}
