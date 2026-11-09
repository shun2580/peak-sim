<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\LabScenarios;

use PeakSim\Lab\Scenarios\CalibrationScenario;
use PeakSim\Runtime\Simulate\Calibrate\Calibrator;
use PeakSim\Runtime\Simulate\Load\ScenarioReader;
use PeakSim\Runtime\Simulate\Load\TwoStage;
use PeakSim\Runtime\Simulate\Predict\PredictStage;
use PeakSim\Runtime\Tests\Unit\Simulate\Load\RecordingIo;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../lab/scenarios/CalibrationScenario.php';

/** The calibration-only scenario file made from the lab's runs: read back as it was recorded, calibration scenarios only. */
final class CalibrationScenarioTest extends TestCase
{
    /**
     * @param array<string, float> $rps
     *
     * @return array{id: string, plan: array<string, mixed>, record: array<string, mixed>}
     */
    private static function calRun(string $id, array $rps, float $utilization, string $status = 'valid'): array
    {
        $routes = [];
        foreach ($rps as $route => $value) {
            $routes[] = ['route' => $route, 'rps' => $value];
        }

        return [
            'id' => $id,
            'plan' => ['mix' => 'even', 'rps' => array_sum($rps), 'routes' => $routes],
            'record' => ['rps' => ['status' => $status], 'db_utilization' => ['utilization' => $utilization]],
        ];
    }

    /** @return list<array{id: string, plan: array<string, mixed>, record: array<string, mixed>}> */
    private static function three(): array
    {
        return [
            self::calRun('cal-1', ['GET /a' => 0.5, 'GET /b/{id}' => 0.5], 0.22445163918990543),
            self::calRun('cal-2', ['GET /a' => 0.8, 'GET /b/{id}' => 0.2], 0.19),
            self::calRun('cal-3', ['GET /a' => 0.2, 'GET /b/{id}' => 0.8], 0.26),
        ];
    }

    public function testReadsBackAsRecordedWithCalibrationScenariosOnly(): void
    {
        $text = CalibrationScenario::build(self::three());
        $parsed = ScenarioReader::parseStageOne($text);

        self::assertSame(2, $parsed['resources']['cores']);
        self::assertSame(['cal-1', 'cal-2', 'cal-3'], array_column($parsed['scenarios'], 'id'));
        self::assertSame(['calibration'], array_values(array_unique(array_column($parsed['scenarios'], 'kind'))));
        self::assertSame(0.22445163918990543, $parsed['scenarios'][0]['observed']);
        self::assertSame(['GET /a' => 0.8, 'GET /b/{id}' => 0.2], $parsed['scenarios'][1]['rps']);
        self::assertSame(['entities' => 10000, 'images' => 10000, 'references' => 10000], $parsed['scenarios'][2]['tables']);
        self::assertStringNotContainsString('kind: target', $text);
    }

    public function testAnInvalidRunIsRefused(): void
    {
        $runs = self::three();
        $runs[1] = self::calRun('cal-2', ['GET /a' => 1.0], 0.2, 'invalid');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cal-2 is not valid');
        CalibrationScenario::build($runs);
    }

    public function testARunWithoutAUtilizationIsRefused(): void
    {
        $runs = self::three();
        unset($runs[0]['record']['db_utilization']);

        $this->expectException(\InvalidArgumentException::class);
        CalibrationScenario::build($runs);
    }

    public function testRatesThatDoNotAddUpToThePlansTotalAreRefused(): void
    {
        $runs = self::three();
        $runs[0]['plan']['rps'] = 2.0;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('add up');
        CalibrationScenario::build($runs);
    }

    public function testADuplicateIdIsRefused(): void
    {
        $runs = self::three();
        $runs[1]['id'] = 'cal-1';

        $this->expectException(\InvalidArgumentException::class);
        CalibrationScenario::build($runs);
    }

    public function testTheScaleAndTablesAreTheLabsDefaultsUnlessGiven(): void
    {
        $parsed = ScenarioReader::parseStageOne(CalibrationScenario::build(self::three(), 500, ['entities'], 4));

        self::assertSame(4, $parsed['resources']['cores']);
        self::assertSame(['entities' => 500], $parsed['scenarios'][0]['tables']);
    }

    // ---- a count of rows of its own for each run (cal-4 is measured at 0.3 x N_0) ----

    public function testEachRunCanHaveItsOwnScaleAndTheOthersKeepTheDefault(): void
    {
        $runs = self::three();
        $runs[] = self::calRun('cal-4', ['GET /a' => 0.5, 'GET /b/{id}' => 0.5], 0.19) + ['scale' => 3000];
        $parsed = ScenarioReader::parseStageOne(CalibrationScenario::build($runs));

        self::assertSame(['cal-1', 'cal-2', 'cal-3', 'cal-4'], array_column($parsed['scenarios'], 'id'));
        foreach (array_slice($parsed['scenarios'], 0, 3) as $s) {
            self::assertSame(['entities' => 10000, 'images' => 10000, 'references' => 10000], $s['tables'], $s['id']);
        }
        self::assertSame(['entities' => 3000, 'images' => 3000, 'references' => 3000], $parsed['scenarios'][3]['tables']);
        self::assertSame(['calibration'], array_values(array_unique(array_column($parsed['scenarios'], 'kind'))));
    }

    public function testTheOtherRunsAreWrittenTheSameWhetherOrNotARunHasItsOwnScale(): void
    {
        $three = CalibrationScenario::build(self::three());
        $runs = self::three();
        $runs[] = self::calRun('cal-4', ['GET /a' => 0.5, 'GET /b/{id}' => 0.5], 0.19) + ['scale' => 3000];
        $four = CalibrationScenario::build($runs);
        // the lines of cal-1..cal-3 (from "scenarios:" to the fourth scenario) are the same bytes
        $head = substr($four, (int) strpos($four, "scenarios:\n"), strpos($four, "\n  - id: cal-4") - (int) strpos($four, "scenarios:\n"));
        self::assertStringContainsString(substr($three, (int) strpos($three, "scenarios:\n")), $head . "\n");
    }

    public function testAScaleThatIsNotAPositiveWholeNumberIsRefused(): void
    {
        $runs = self::three();
        $runs[0]['scale'] = 0;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('scale of cal-1');
        CalibrationScenario::build($runs);
    }

    public function testTheRecordOnlyRunIsNeverACalibrationScenario(): void
    {
        $runs = self::three();
        $runs[] = self::calRun('cal-3-orig', ['GET /a' => 0.8, 'GET /b/{id}' => 0.2], 0.0968);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cal-3-orig is a record only');
        CalibrationScenario::build($runs);
    }

    // ---- the three runs that were recorded give what they gave before ----

    private const CALIBRATION_DIR = __DIR__ . '/../../../../../docs/research/lab-data/calibration';

    /** @return list<array{id: string, plan: array<string, mixed>, record: array<string, mixed>, plan_sha256: string, record_sha256: string}> */
    private static function recordedRuns(): array
    {
        $runs = [];
        foreach (['cal-1', 'cal-2', 'cal-3'] as $id) {
            $run = ['id' => $id];
            foreach (['plan', 'record'] as $kind) {
                $path = self::CALIBRATION_DIR . '/' . $id . '/' . $kind . '.json';
                self::assertFileExists($path);
                $text = (string) file_get_contents($path);
                $run[$kind] = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
                $run[$kind . '_sha256'] = hash('sha256', $text);
            }
            $runs[] = $run;
        }

        return $runs;
    }

    public function testTheThreeRecordedRunsGiveTheCommittedScenarioFileByteForByte(): void
    {
        $committed = self::CALIBRATION_DIR . '/fit/calibration.yaml';
        self::assertFileExists($committed);
        self::assertSame((string) file_get_contents($committed), CalibrationScenario::build(self::recordedRuns()));
    }

    public function testTheThreeRecordedRunsGiveTheCommittedCoefficients(): void
    {
        $estimateDir = self::CALIBRATION_DIR . '/../estimate';
        $files = [
            '/est/estimate.json' => (string) file_get_contents($estimateDir . '/estimate.json'),
            '/in/calibration.yaml' => CalibrationScenario::build(self::recordedRuns()),
        ];
        $loaded = (new TwoStage(new RecordingIo($files)))->stageOne('/est', '/in/calibration.yaml', '/out')->toArray();
        $calibration = (new Calibrator())->calibrate(PredictStage::points($loaded));

        $committed = json_decode((string) file_get_contents(self::CALIBRATION_DIR . '/fit/calibration-result.json'), true, 512, JSON_THROW_ON_ERROR)['calibration'];
        $two = $calibration->fits[0];
        $reference = $committed['fits'][0];
        self::assertSame('two_coefficient', $reference['kind']);
        self::assertSame($reference['c_query'], $two->cQuery);
        self::assertSame($reference['c_row'], $two->cRow);
        self::assertFalse($calibration->hasCutCoefficient());
        self::assertSame(3, $calibration->pointCount);
    }

    // ---- the command line ----

    /** @return array{int, string, string} exit code, standard output, standard error */
    private static function cli(string ...$args): array
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../../../../lab/scenarios/make-calibration-scenario.php', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    public function testTheCommandLineGivesARunItsOwnScaleAndRefusesWhatItCannotUse(): void
    {
        $dir = self::CALIBRATION_DIR;
        [$code, $out] = self::cli("--run=cal-1:$dir/cal-1", "--run=cal-2:$dir/cal-2", "--run=cal-3:$dir/cal-3", "--run=cal-4:$dir/cal-1", '--run-scale=cal-4:3000');
        self::assertSame(0, $code);
        $parsed = ScenarioReader::parseStageOne($out);
        self::assertSame([10000, 10000, 10000, 3000], array_map(static fn (array $s): int => $s['tables']['entities'], $parsed['scenarios']));

        [$code, $out] = self::cli("--run=cal-1:$dir/cal-1", "--run=cal-2:$dir/cal-2", "--run=cal-3:$dir/cal-3");
        self::assertSame(0, $code);
        self::assertSame((string) file_get_contents($dir . '/fit/calibration.yaml'), $out);

        [$code, , $err] = self::cli("--run=cal-1:$dir/cal-1", '--run-scale=cal-9:3000');
        self::assertSame(2, $code);
        self::assertStringContainsString('names a run that was not given: cal-9', $err);

        [$code, , $err] = self::cli("--run=cal-1:$dir/cal-1", '--run-scale=cal-1:3000', '--run-scale=cal-1:4000');
        self::assertSame(2, $code);
        self::assertStringContainsString('given twice', $err);

        [$code, , $err] = self::cli("--run=cal-1:$dir/cal-1", "--run=cal-3-orig:$dir/cal-3-orig");
        self::assertSame(2, $code);
        self::assertStringContainsString('cal-3-orig is a record only', $err);
    }
}
