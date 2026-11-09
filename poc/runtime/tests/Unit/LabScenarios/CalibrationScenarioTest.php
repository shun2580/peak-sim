<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\LabScenarios;

use PeakSim\Lab\Scenarios\CalibrationScenario;
use PeakSim\Runtime\Simulate\Load\ScenarioReader;
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
}
