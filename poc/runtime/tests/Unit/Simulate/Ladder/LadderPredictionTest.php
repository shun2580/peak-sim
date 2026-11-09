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
    /**
     * The calibration scenarios: id, rps, observed, tables. Three at N_0 (1,000 rows here) and a fourth at 0.3 x N_0, as the lab has them. The
     * observed values are the made-up model (c_query 0.004, c_row 0.0002 over 2 cores) with a little noise, chosen so that no fit of the width
     * (the four that leave one scenario out, and the one on all four) has a coefficient cut to 0.
     */
    private const CALIBRATIONS = [
        ['cal-1', '{ "GET /a": 5.0, "POST /m": 5.0 }', '0.4515', '{ pages: 1000, books: 1000 }'],
        ['cal-2', '{ "GET /a": 8.0, "POST /m": 2.0 }', '0.4694', '{ pages: 1000, books: 1000 }'],
        ['cal-3', '{ "GET /a": 2.0, "POST /m": 8.0 }', '0.4256', '{ pages: 1000, books: 1000 }'],
        ['cal-4', '{ "GET /a": 5.0, "POST /m": 5.0 }', '0.194', '{ pages: 300, books: 300 }'],
    ];

    /** The observed utilisation of the calibration scenario the naive estimate is taken from (the largest at N_0: cal-2). */
    private const NAIVE_OBSERVED = 0.4694;

    /** @var array<string, string> id => rps map as YAML text (a target scenario of the made-up file) */
    private const TARGETS = [
        't-1' => '{ "GET /a": 6.0, "POST /m": 2.0, "GET /axisless": 2.0 }',
        't-2' => '{ "GET /a": 2.0, "POST /m": 8.0 }',
    ];

    private const TARGET_TABLES = ['t-1' => '{ pages: 3000, books: 3000 }', 't-2' => '{ pages: 10000, books: 10000 }'];

    /**
     * @param array<string, string>                                      $targets      id => rps map as YAML text
     * @param list<array{0: string, 1: string, 2: string, 3: string}>|null $calibrations the calibration scenarios (the lab's four when null)
     */
    private static function yaml(array $targets = self::TARGETS, ?array $calibrations = null): string
    {
        $y = "schema_version: \"0.1\"\nresources:\n  db_cpu:\n    cores: 2\n    threshold: 0.8\n  php_memory_limit: 128M\nscenarios:\n";
        foreach ($calibrations ?? self::CALIBRATIONS as [$id, $rps, $observed, $tables]) {
            $y .= "  - id: {$id}\n    kind: calibration\n    tables: {$tables}\n    rps: {$rps}\n    observed: { db_cpu_utilization: {$observed} }\n";
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

    /**
     * @param array<string, string> $safe     count => the estimate document (JSON text) made at that count, each given as a safe-side estimate
     * @param string|null           $source   how the commit of the main estimate was found (null: "argument" when there is a commit)
     *
     * @return array{int, string, string, RecordingIo}
     */
    private static function ladder(string $yaml, ?string $commit = 'abc123', array $safe = [], bool $require = false, ?string $expected = null, ?string $source = null): array
    {
        $io = Fixtures::io($yaml);
        $given = [];
        foreach ($safe as $count => $json) {
            $io->files['/safe' . $count . '/estimate.json'] = $json;
            $given[] = ['dir' => '/safe' . $count, 'commit' => 'safe' . $count . 'c', 'commit_source' => 'git'];
        }
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $next = new LadderStage('/est', Fixtures::SCENARIO_PATH, $out, $err, $commit, $commit === null ? null : ($source ?? 'argument'), $io, $given, $require, $expected);
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
        // the calibration scenarios all add up to 10 rps; cal-2 has the largest observed utilisation among those at N_0
        self::assertSame('cal-2', $r['naive']['basis_scenario']);
        foreach ($r['scenarios'] as $s) {
            foreach ($s['stages'] as $stage) {
                self::assertEqualsWithDelta(self::NAIVE_OBSERVED * $stage['rps_total'] / 10.0, $stage['naive'], 1e-12);
            }
            // threshold 0.8: 0.8 x 10 / 0.4694 rps
            self::assertEqualsWithDelta(0.8 * 10.0 / self::NAIVE_OBSERVED, $s['limit_rps']['naive']['rps'], 1e-9);
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

    // ---- four calibration scenarios, a smaller count for the fourth, and the naive estimate ----

    public function testFourCalibrationScenariosGiveTheFitOnAllFourAndFourLeaveOneOutFits(): void
    {
        $r = self::ladderReport(self::yaml());
        self::assertSame(4, $r['calibration']['calibration_scenarios']);
        self::assertFalse($r['calibration']['cut_coefficients']);
        self::assertSame('leave_one_out', $r['calibration']['width']['status']);
        self::assertSame(['all', 'without:cal-1', 'without:cal-2', 'without:cal-3', 'without:cal-4'], array_column($r['calibration']['width']['fits'], 'label'));
        foreach ($r['calibration']['width']['fits'] as $fit) {
            self::assertFalse($fit['cut_coefficient'], $fit['label']);
            self::assertGreaterThan(0.0, $fit['c_query']);
            self::assertGreaterThan(0.0, $fit['c_row']);
        }
    }

    public function testTheNaiveEstimateIsChosenOnlyAmongTheCalibrationScenariosAtTheLargestCount(): void
    {
        $r = self::ladderReport(self::yaml());
        self::assertSame(1000, $r['naive']['basis_count']);
        self::assertSame(['cal-1', 'cal-2', 'cal-3'], $r['naive']['candidates']);
        self::assertSame([['id' => 'cal-4', 'count' => 300]], $r['naive']['not_candidates']);

        // cal-4 with the largest rps total and the largest observed utilisation of all: it is still not the basis
        $calibrations = self::CALIBRATIONS;
        $calibrations[3] = ['cal-4', '{ "GET /a": 20.0, "POST /m": 20.0 }', '0.95', '{ pages: 300, books: 300 }'];
        $io = Fixtures::io(self::yaml(self::TARGETS, $calibrations));
        $loaded = (new TwoStage($io))->stageOne('/est', Fixtures::SCENARIO_PATH, '/out')->toArray();
        $report = LadderPrediction::build(
            $loaded,
            (new Calibrator())->calibrate(PredictStage::points($loaded)),
            ['estimate_commit' => null, 'estimate_commit_source' => null, 'estimate_sha256' => 'x', 'scenario_sha256' => 'y'],
        );
        self::assertSame('cal-2', $report['naive']['basis_scenario']);
        self::assertSame(['cal-1', 'cal-2', 'cal-3'], $report['naive']['candidates']);
        self::assertEqualsWithDelta(self::NAIVE_OBSERVED, $report['naive']['basis_utilization'], 1e-12);
    }

    // ---- the calibration range is a record ----

    public function testTheCalibrationRangeGivesEachFitsCoefficientsUtilisationAtEveryStageAndLimitRps(): void
    {
        $r = self::ladderReport(self::yaml());
        foreach ($r['scenarios'] as $s) {
            $range = $s['calibration_range'];
            self::assertFalse($range['for_the_decision']);
            self::assertSame('leave_one_out', $range['status']);
            self::assertSame(['all', 'without:cal-1', 'without:cal-2', 'without:cal-3', 'without:cal-4'], array_column($range['fits'], 'label'));
            self::assertCount(13, $range['stages']);
            $limits = [];
            foreach ($range['fits'] as $fit) {
                self::assertCount(13, $fit['utilization']);
                self::assertSame('found', $fit['limit_rps']['status']);
                // the proportional form: the utilisation at a total T is T / limit x the threshold, so at T = 1.0 (the 9th stage) it is 0.8 / limit
                $u = $fit['limit_rps']['rps'];
                self::assertEqualsWithDelta(0.8 / $u, $fit['utilization'][8], 1e-9, $fit['label']);
                $limits[] = $u;
            }
            self::assertSame(['min' => min($limits), 'max' => max($limits)], $range['limit_rps']);
            foreach ($range['stages'] as $i => $row) {
                self::assertSame($r['stages_rps_total'][$i], $row['rps_total']);
                $point = $s['stages'][$i]['utilization']['point'];
                self::assertLessThanOrEqual($point + 1e-12, $row['min']);
                self::assertGreaterThanOrEqual($point - 1e-12, $row['max']);
            }
            // the fit on all the calibration scenarios is the point: its limit is the point limit
            self::assertEqualsWithDelta($s['limit_rps']['point']['rps'], $range['fits'][0]['limit_rps']['rps'], 1e-9);
        }
    }

    // ---- stop conditions ----

    public function testACutCoefficientStopsBeforeAnyPredictionIsWritten(): void
    {
        // three runs at one count, as the lab had them before cal-4: a leave-one-out fit cuts a coefficient to 0
        $old = [
            ['cal-1', '{ "GET /a": 5.0, "POST /m": 5.0 }', '0.20', '{ pages: 1000, books: 300 }'],
            ['cal-2', '{ "GET /a": 8.0, "POST /m": 2.0 }', '0.27', '{ pages: 1000, books: 300 }'],
            ['cal-3', '{ "GET /a": 2.0, "POST /m": 8.0 }', '0.14', '{ pages: 1000, books: 300 }'],
        ];
        [$code, , $stderr, $io] = self::ladder(self::yaml(self::TARGETS, $old));
        self::assertSame(LadderStage::EXIT_CUT_COEFFICIENT, $code);
        self::assertSame(33, $code);
        self::assertStringContainsString('was cut to 0', $stderr);
        self::assertStringContainsString('without:cal-', $stderr);
        self::assertArrayNotHasKey('/out/ladder-prediction.json', $io->files);
    }

    public function testACalibrationScenarioThatIsARecordOnlyIsRefused(): void
    {
        $calibrations = self::CALIBRATIONS;
        $calibrations[] = ['cal-3-orig', '{ "GET /a": 2.0, "POST /m": 8.0 }', '0.30', '{ pages: 1000, books: 1000 }'];
        [$code, , $stderr, $io] = self::ladder(self::yaml(self::TARGETS, $calibrations));
        self::assertSame(34, $code);
        self::assertStringContainsString('cal-3-orig', $stderr);
        self::assertStringContainsString('record only', $stderr);
        self::assertArrayNotHasKey('/out/ladder-prediction.json', $io->files);
    }

    // ---- where the estimate came from ----

    public function testTheOutputSaysWhetherTheEstimateCommitIsTheExpectedOneFoundInGit(): void
    {
        [, $stdout, , $io] = self::ladder(self::yaml(), '7c4077412345', [], false, '7c40774', 'git');
        $r = json_decode($io->files['/out/ladder-prediction.json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('7c40774', $r['inputs']['estimate_commit_expected']);
        self::assertTrue($r['inputs']['estimate_commit_is_expected']);
        self::assertStringContainsString('as expected (7c40774)', $stdout);

        // a different commit, and the right commit given by hand: both are "not the expected one"
        foreach ([['deadbeef', 'git'], ['7c4077412345', 'argument']] as [$commit, $source]) {
            [, $out, , $io] = self::ladder(self::yaml(), $commit, [], false, '7c40774', $source);
            $r = json_decode($io->files['/out/ladder-prediction.json'], true, 512, JSON_THROW_ON_ERROR);
            self::assertFalse($r['inputs']['estimate_commit_is_expected'], $commit . ' ' . $source);
            self::assertStringContainsString('NOT the expected 7c40774', $out);
        }

        [, , , $io] = self::ladder(self::yaml());
        $r = json_decode($io->files['/out/ladder-prediction.json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertNull($r['inputs']['estimate_commit_expected']);
        self::assertNull($r['inputs']['estimate_commit_is_expected']);
    }

    // ---- the marks of the safe side are the marks at the scenario's own count ----

    /**
     * The made-up estimate again, as it would be made at `$at` rows: the same formulas (so the same point), and the marks of that size.
     * GET /a is marked here (its t1 gets an upper side of 2 x the point) and POST /m's upper side is 9 x the point instead of 5 x.
     */
    private static function estimateAt(int $at, bool $changePoint = false): string
    {
        $doc = Fixtures::estimateDocument();
        $doc['at'] = $at;
        foreach ($doc['routes'] as &$route) {
            if ($route['route'] === 'GET /a') {
                $route['marked'] = true;
                $route['types'][0]['marks']['plan_changes_at_scale'] = true;
                $route['types'][0]['formula']['upper'] = ['at_n' => 2.0 * (10.0 + 0.1 * $at), 'h1_at_n' => 10.0 + 0.1 * $at, 'ratio' => 2.0];
                if ($changePoint) {
                    $route['types'][0]['formula']['per_exec']['a'] = 11.0;
                }
            }
            if ($route['route'] === 'POST /m') {
                $route['types'][0]['formula']['upper'] = ['at_n' => 9.0 * $at, 'h1_at_n' => (float) $at, 'ratio' => 9.0];
            }
        }
        unset($route);

        return json_encode($doc, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @return array<string, mixed> */
    private static function decoded(RecordingIo $io): array
    {
        return json_decode($io->files['/out/ladder-prediction.json'], true, 512, JSON_THROW_ON_ERROR);
    }

    public function testAScenarioGetsTheMarksOfTheEstimateMadeAtItsOwnCount(): void
    {
        // t-1 reads 3,000 rows. Without a safe-side estimate it takes the main estimate's marks (made at 200,000 rows) and says so.
        $without = self::decoded(self::ladder(self::yaml())[3]);
        self::assertSame(
            [['count' => 3000, 'source' => 'main_estimate_other_count', 'estimate_at' => 200000, 'marked_routes' => ['POST /m']]],
            $without['scenarios'][0]['safe_side'],
        );

        [$code, $stdout, $stderr, $io] = self::ladder(self::yaml(), 'abc123', [3000 => self::estimateAt(3000)]);
        self::assertSame(0, $code, $stderr);
        $r = self::decoded($io);
        self::assertSame(
            [['count' => 3000, 'source' => 'estimate_at_count', 'estimate_at' => 3000, 'marked_routes' => ['GET /a', 'POST /m']]],
            $r['scenarios'][0]['safe_side'],
        );
        self::assertSame(['GET /a', 'POST /m'], $r['scenarios'][0]['marked_routes']);
        // t-2 reads 10,000 rows: no estimate at that count was given, so it keeps the main estimate's marks
        self::assertSame('main_estimate_other_count', $r['scenarios'][1]['safe_side'][0]['source']);
        self::assertSame(['POST /m'], $r['scenarios'][1]['marked_routes']);
        self::assertStringContainsString('t-1 safe side: marks of the estimate made at 3,000 rows (estimate_at_count) for 3,000 rows', $stdout);

        // the point does not move; the safe side of t-1 does (more routes are marked, and POST /m's upper side is larger)
        foreach ($r['scenarios'][0]['stages'] as $i => $stage) {
            self::assertSame(self::number($without['scenarios'][0]['stages'][$i]['utilization']['point']), self::number($stage['utilization']['point']));
            self::assertGreaterThan($without['scenarios'][0]['stages'][$i]['utilization']['upper'], $stage['utilization']['upper']);
        }
        // t-2 is untouched
        self::assertSame($without['scenarios'][1]['stages'], $r['scenarios'][1]['stages']);
        self::assertSame($without['scenarios'][1]['limit_rps'], $r['scenarios'][1]['limit_rps']);
        // the output names the estimates, with no path
        self::assertSame([3000, 200000], array_column($r['inputs']['estimates_for_marks'], 'at'));
        self::assertSame(['safe_side', 'main'], array_column($r['inputs']['estimates_for_marks'], 'role'));
        self::assertSame('safe3000c', $r['inputs']['estimates_for_marks'][0]['commit']);
        self::assertSame(hash('sha256', self::estimateAt(3000)), $r['inputs']['estimates_for_marks'][0]['sha256']);
        self::assertStringNotContainsString('/safe3000', $io->files['/out/ladder-prediction.json']);
    }

    public function testTheSafeSideAtACountIsWhatBinSimulateGivesWithTheEstimateMadeAtThatCountAsTheOnlyEstimate(): void
    {
        $r = self::decoded(self::ladder(self::yaml(), 'abc123', [3000 => self::estimateAt(3000)])[3]);
        // the same scenario file read with the 3,000-row estimate as the only estimate
        $io = Fixtures::io(self::yaml(), self::estimateAt(3000));
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $code = (new SimulateCommand($out, $err, $io, new LadderStage('/est', Fixtures::SCENARIO_PATH, $out, $err, 'abc123', 'argument', $io)))
            ->run(['--estimate=/est', '--scenario=' . Fixtures::SCENARIO_PATH, '--out=/out']);
        self::assertSame(0, $code);
        $alone = self::decoded($io);
        foreach ($r['scenarios'][0]['stages'] as $i => $stage) {
            self::assertSame(self::number($alone['scenarios'][0]['stages'][$i]['utilization']['upper']), self::number($stage['utilization']['upper']), 'stage ' . $i);
            self::assertSame(self::number($alone['scenarios'][0]['stages'][$i]['utilization']['point']), self::number($stage['utilization']['point']), 'stage ' . $i);
        }
        self::assertSame($alone['scenarios'][0]['limit_rps']['safe'], $r['scenarios'][0]['limit_rps']['safe']);
    }

    public function testEveryCountNeedsAnEstimateMadeAtItWhenTheLabEntryAsksForIt(): void
    {
        [$code, , $stderr, $io] = self::ladder(self::yaml(), 'abc123', [3000 => self::estimateAt(3000)], true);
        self::assertSame(35, $code);
        self::assertStringContainsString('t-2 reads 10,000 rows', $stderr);
        self::assertStringNotContainsString('t-1 reads', $stderr);
        self::assertArrayNotHasKey('/out/ladder-prediction.json', $io->files);

        [$code, , $stderr, $io] = self::ladder(self::yaml(), 'abc123', [3000 => self::estimateAt(3000), 10000 => self::estimateAt(10000)], true);
        self::assertSame(0, $code, $stderr);
        $r = self::decoded($io);
        self::assertSame([3000, 10000], array_map(static fn (array $s): int => $s['safe_side'][0]['count'], $r['scenarios']));
        self::assertSame(['estimate_at_count', 'estimate_at_count'], array_map(static fn (array $s): string => $s['safe_side'][0]['source'], $r['scenarios']));
    }

    public function testTheMainEstimateServesItsOwnCount(): void
    {
        $main = Fixtures::estimateDocument();
        $main['at'] = 3000;
        $io = Fixtures::io(self::yaml(), json_encode($main, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $next = new LadderStage('/est', Fixtures::SCENARIO_PATH, $out, $err, 'abc123', 'git', $io, [], true);
        $code = (new SimulateCommand($out, $err, $io, $next))->run(['--estimate=/est', '--scenario=' . Fixtures::SCENARIO_PATH, '--out=/out']);
        self::assertSame(35, $code, 'the main estimate was made at 3,000 rows: t-2 (10,000 rows) has no estimate');
        rewind($err);
        self::assertStringContainsString('t-2 reads 10,000 rows', (string) stream_get_contents($err));
    }

    public function testASafeSideEstimateWhosePointDiffersIsRefused(): void
    {
        [$code, , $stderr, $io] = self::ladder(self::yaml(), 'abc123', [3000 => self::estimateAt(3000, true)]);
        self::assertSame(36, $code);
        self::assertStringContainsString('the point differs', $stderr);
        self::assertArrayNotHasKey('/out/ladder-prediction.json', $io->files);
    }

    public function testTwoEstimatesForOneCountAreRefused(): void
    {
        $io = Fixtures::io(self::yaml());
        $io->files['/a/estimate.json'] = self::estimateAt(3000);
        $io->files['/b/estimate.json'] = self::estimateAt(3000);
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $next = new LadderStage('/est', Fixtures::SCENARIO_PATH, $out, $err, 'abc123', 'git', $io, [
            ['dir' => '/a', 'commit' => 'a', 'commit_source' => 'git'],
            ['dir' => '/b', 'commit' => 'b', 'commit_source' => 'git'],
        ]);
        $code = (new SimulateCommand($out, $err, $io, $next))->run(['--estimate=/est', '--scenario=' . Fixtures::SCENARIO_PATH, '--out=/out']);
        self::assertSame(36, $code);
        rewind($err);
        self::assertStringContainsString('two estimates were given for the count 3000', (string) stream_get_contents($err));
    }
}
