<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Simulate\Predict;

use PeakSim\Runtime\Simulate\Predict\AccuracyRecord;
use PeakSim\Runtime\Simulate\Predict\AppLimitRisk;
use PeakSim\Runtime\Simulate\Predict\AppMemoryRisk;
use PeakSim\Runtime\Simulate\Predict\MemoryAccuracy;
use PeakSim\Runtime\Simulate\Predict\PredictionReport;
use PeakSim\Runtime\Simulate\Predict\PredictionText;
use PHPUnit\Framework\TestCase;

/**
 * The memory side of the `app_limit_risk` mark on made-up routes. The factor is AppMemoryRisk::MEASURED_OVER_PREDICTED (1.51) and the limit
 * of most tests is 512 MiB = 536,870,912 bytes; a route is marked when 1.51 x its predicted peak memory reaches the limit, that is when the peak
 * is at least 355,543,650.3 bytes. The expected values are worked out by hand below.
 */
final class AppMemoryRiskTest extends TestCase
{
    private const LIMIT = 536870912;

    /** @return array<string, mixed> a route with a predicted peak memory */
    private static function route(string $name, float $peak): array
    {
        return PredictFixtures::route($name, 1.0, 5.0, 1000.0, null, ['peak_memory' => $peak, 'peak_memory_status' => 'predicted']);
    }

    /**
     * 'GET /heavy'  700,000,000 bytes -> bound 1,057,000,000 (1.97 of the limit)  marked
     * 'GET /light'  100,000,000 bytes -> bound 151,000,000 (0.28 of the limit)    not marked
     * 'GET /under'  355,000,000 bytes -> bound 536,050,000 (just under)           not marked
     * 'GET /over'   356,000,000 bytes -> bound 537,560,000 (just over)            marked
     *
     * @return list<array<string, mixed>>
     */
    private static function routes(): array
    {
        return [
            self::route('GET /heavy', 700000000.0),
            self::route('GET /light', 100000000.0),
            self::route('GET /under', 355000000.0),
            self::route('GET /over', 356000000.0),
        ];
    }

    /** @return array<string, string> route => status */
    private static function statuses(array $risk): array
    {
        return array_column($risk['routes'], 'status', 'route');
    }

    public function testARouteOverTheLimitIsMarkedAndOneUnderItIsNot(): void
    {
        $risk = AppMemoryRisk::evaluate(self::routes(), self::LIMIT);

        self::assertSame(
            ['GET /heavy' => AppMemoryRisk::MARKED, 'GET /light' => AppMemoryRisk::NOT_MARKED, 'GET /under' => AppMemoryRisk::NOT_MARKED, 'GET /over' => AppMemoryRisk::MARKED],
            self::statuses($risk),
        );
        self::assertSame(['GET /heavy', 'GET /over'], $risk['marked_routes']);
        self::assertSame(self::LIMIT, $risk['limit_bytes']);
        self::assertSame(1.51, $risk['measured_over_predicted']);
        self::assertEqualsWithDelta(1057000000.0, $risk['routes'][0]['bound_bytes'], 1e-3);
        self::assertEqualsWithDelta(700000000.0, $risk['routes'][0]['peak_memory_bytes'], 1e-9);
        self::assertEqualsWithDelta(1057000000.0 / self::LIMIT, $risk['routes'][0]['share_of_limit'], 1e-9);
        self::assertEqualsWithDelta(536050000.0 / self::LIMIT, $risk['routes'][2]['share_of_limit'], 1e-9);
        self::assertNull($risk['routes'][0]['reason']);
    }

    public function testTheMarkFollowsTheLimitThatWasGiven(): void
    {
        // the bounds are 1,057,000,000 / 151,000,000 / 536,050,000 / 537,560,000 bytes
        self::assertSame(['GET /heavy'], AppMemoryRisk::evaluate(self::routes(), 1000000000)['marked_routes']);
        self::assertSame(['GET /heavy', 'GET /under', 'GET /over'], AppMemoryRisk::evaluate(self::routes(), 536000000)['marked_routes']);
        self::assertSame([], AppMemoryRisk::evaluate(self::routes(), 2000000000)['marked_routes']);
        self::assertSame(['GET /heavy', 'GET /light', 'GET /under', 'GET /over'], AppMemoryRisk::evaluate(self::routes(), 150000000)['marked_routes']);
    }

    public function testTheLineItselfIsMarked(): void
    {
        // 1.51 x 100 is 151 exactly in floating point
        $risk = AppMemoryRisk::evaluate([self::route('GET /x', 100.0)], 151);
        self::assertSame(['GET /x'], $risk['marked_routes']);
        self::assertSame([], AppMemoryRisk::evaluate([self::route('GET /x', 100.0)], 152)['marked_routes']);
    }

    public function testARouteWithNoPeakMemoryEstimateIsNotJudgedAndIsNeverMarked(): void
    {
        $routes = [
            PredictFixtures::route('GET /not-given', 1.0, 5.0, 1000.0),
            PredictFixtures::route('GET /not-predicted', 1.0, 5.0, 1000.0, null, ['peak_memory_status' => 'not_predicted']),
            PredictFixtures::unknown('GET /unknown', 3.0),
            self::route('GET /heavy', 700000000.0),
        ];
        $risk = AppMemoryRisk::evaluate($routes, self::LIMIT);
        self::assertSame(
            ['GET /not-given' => 'not_evaluated', 'GET /not-predicted' => 'not_evaluated', 'GET /unknown' => 'not_evaluated', 'GET /heavy' => 'marked'],
            self::statuses($risk),
        );
        self::assertSame(['not_given', 'not_predicted', 'unknown', null], array_column($risk['routes'], 'reason'));
        self::assertNull($risk['routes'][0]['bound_bytes']);
        self::assertNull($risk['routes'][0]['share_of_limit']);
        self::assertSame(['GET /heavy'], $risk['marked_routes']);
    }

    public function testTheStatusDecidesWhetherAFigureIsUsed(): void
    {
        $risk = AppMemoryRisk::evaluate([PredictFixtures::route('GET /stale', 1.0, 5.0, 1000.0, null, ['peak_memory' => 900000000.0, 'peak_memory_status' => 'not_predicted'])], self::LIMIT);
        self::assertSame(['GET /stale' => 'not_evaluated'], self::statuses($risk));
        self::assertSame([], $risk['marked_routes']);
    }

    public function testAStatusOfPredictedWithNoValueIsNotJudged(): void
    {
        $risk = AppMemoryRisk::evaluate([PredictFixtures::route('GET /odd', 1.0, 5.0, 1000.0, null, ['peak_memory_status' => 'predicted'])], self::LIMIT);
        self::assertSame(['GET /odd' => 'not_evaluated'], self::statuses($risk));
    }

    public function testTheMarkDoesNotDependOnTheRps(): void
    {
        $routes = self::routes();
        $routes[0]['rps'] = 0.001;
        $routes[1]['rps'] = 500.0;
        self::assertSame(['GET /heavy', 'GET /over'], AppMemoryRisk::evaluate($routes, self::LIMIT)['marked_routes']);
    }

    public function testTheLimitRpsNoteIsThereOnlyWhenARouteIsMarked(): void
    {
        self::assertSame(AppLimitRisk::LIMIT_RPS_NOTE, AppMemoryRisk::evaluate(self::routes(), self::LIMIT)['limit_rps_note']);
        self::assertNull(AppMemoryRisk::evaluate(self::routes(), 2000000000)['limit_rps_note']);
    }

    public function testALimitOfZeroOrLessIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AppMemoryRisk::evaluate(self::routes(), 0);
    }

    public function testTheFactorIsTheOneTheRecordsGive(): void
    {
        $file = dirname(__DIR__, 6) . '/docs/research/lab-data/diagnosis/app-memory-risk/factor.json';
        self::assertFileExists($file);
        $record = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($record['factor'], AppMemoryRisk::MEASURED_OVER_PREDICTED);
    }

    public function testTheRuleOnTheRecordsOfTheLabMarksTheBookPageAtTwoHundredThousandRowsAndNoRouteIsMissed(): void
    {
        $file = dirname(__DIR__, 6) . '/docs/research/lab-data/diagnosis/app-memory-risk/factor.json';
        $record = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $misses = 0;
        $hit = false;
        foreach ($record['judged_on_the_lab_records'] as $cell) {
            $risk = AppMemoryRisk::evaluate([self::route($cell['route'], (float) $cell['predicted_peak_bytes'])], $record['memory_limit_bytes_of_the_records']);
            self::assertSame($cell['marked'], $risk['marked_routes'] !== [], $cell['route'] . ' at ' . $cell['rows'] . ' rows: the tool and the script agree');
            if ($cell['outcome'] === 'miss') {
                ++$misses;
            }
            if ($cell['outcome'] === 'hit' && $cell['route'] === 'GET /books/{slug}' && $cell['rows'] === 200000) {
                $hit = true;
            }
        }
        self::assertSame(0, $misses);
        self::assertTrue($hit);
    }

    // ---- the whole report -------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> the loaded input of the report test's three routes, with an optional memory limit and an optional execution limit */
    private static function loaded(?int $memoryLimit, ?float $cpuLimit = null): array
    {
        $routes = [
            self::route('GET /a', 100000000.0),
            self::route('GET /b', 400000000.0),
            PredictFixtures::unknown('GET /u', 1.0),
        ];
        $resources = ['cores' => 2, 'threshold' => 0.8, 'php_memory_limit_bytes' => 134217728];
        if ($memoryLimit !== null) {
            $resources['app_memory_limit_bytes'] = $memoryLimit;
        }
        if ($cpuLimit !== null) {
            $resources['app_cpu_time_limit_s'] = $cpuLimit;
        }

        return [
            'schema_version' => '0.1',
            'app' => 'demo',
            'estimate_at' => 200000,
            'resources' => $resources,
            'scenarios' => [
                ['id' => 'cal-1', 'kind' => 'calibration', 'tables' => ['items' => 10000], 'observed' => ['db_cpu_utilization' => 0.21], 'unknown_tables' => [], 'zero_rps_routes' => [], 'routes' => $routes],
                ['id' => 'peak-1', 'kind' => 'target', 'tables' => ['items' => 100000], 'unknown_tables' => [], 'zero_rps_routes' => [], 'routes' => $routes],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function report(?int $memoryLimit, ?float $cpuLimit = null, ?MemoryAccuracy $accuracy = null): array
    {
        $memory = ['GET /a' => ['table' => 'items', 'a' => 0.0, 'b' => 1000.0, 'k' => 1.0], 'GET /b' => ['table' => 'items', 'a' => 0.0, 'b' => 4000.0, 'k' => 1.0]];
        $acc = $accuracy ?? MemoryAccuracy::fromArray(['schema_version' => '1', 'source' => 'test', 'within' => ['GET /a']]);

        return PredictionReport::build(self::loaded($memoryLimit, $cpuLimit), PredictFixtures::twoCoefficient(0.001, 0.0001, 0.0005, 0.0002), $memory, $acc, AccuracyRecord::notChecked());
    }

    private static function json(array $report): string
    {
        return json_encode($report, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /** The bytes of the output with no limit at all are fixed by the golden in AppLimitRiskTest; this one fixes that the memory side leaves no key and no line. */
    public function testWithNoMemoryLimitGivenTheOutputHasNoTraceOfTheMemoryMark(): void
    {
        $report = self::report(null);
        self::assertStringNotContainsString('app_limit_risk_memory', self::json($report));
        self::assertStringNotContainsString('app_memory_limit', self::json($report));
        self::assertStringNotContainsString('of memory:', PredictionText::render($report));
    }

    public function testWithOnlyTheExecutionLimitGivenTheOutputIsTheSameAsBeforeTheMemorySideExisted(): void
    {
        $cpuOnly = self::report(null, 2.0);
        self::assertArrayHasKey('app_limit_risk', $cpuOnly['scenarios'][0]);
        self::assertArrayNotHasKey('app_limit_risk_memory', $cpuOnly['scenarios'][0]);
        self::assertStringNotContainsString('app_memory_limit', self::json($cpuOnly));
        self::assertStringNotContainsString('of memory:', PredictionText::render($cpuOnly));
    }

    public function testAGivenMemoryLimitAddsTheBlockToEveryTargetScenarioAndNothingElseChanges(): void
    {
        $without = self::report(null);
        // 'GET /a' 100,000 x 1000 = 100,000,000 bytes at 100,000 rows, bound 151,000,000 (under 512 MiB); 'GET /b' 400,000,000 bytes, bound 604,000,000 (over)
        $with = self::report(self::LIMIT);
        $risk = $with['scenarios'][0]['app_limit_risk_memory'];
        self::assertSame(['GET /a' => 'not_marked', 'GET /b' => 'marked', 'GET /u' => 'not_evaluated'], self::statuses($risk));
        self::assertSame(self::LIMIT, $with['resources']['app_memory_limit_bytes']);

        unset($with['scenarios'][0]['app_limit_risk_memory'], $with['resources']['app_memory_limit_bytes']);
        self::assertSame($without, $with, 'the utilisation, the limit multiplier and everything else are the same with and without the memory limit');
    }

    public function testTheTwoSidesAreJudgedApartAndTheOutputSaysWhichLimitMarkedTheRoute(): void
    {
        // 2.0 s of execution time marks 'GET /b'?  The loaded routes are 5 queries and 1,000 rows: 0.105 s, bound 0.957 s, so no route is marked by time
        $both = self::report(self::LIMIT, 2.0);
        self::assertSame([], $both['scenarios'][0]['app_limit_risk']['marked_routes']);
        self::assertSame(['GET /b'], $both['scenarios'][0]['app_limit_risk_memory']['marked_routes']);
        $text = PredictionText::render($both);
        self::assertStringContainsString('app limit    2 s of cpu time: 0 route(s) marked app_limit_risk', $text);
        self::assertStringContainsString('app limit    512.0 MiB of memory: 1 route(s) marked app_limit_risk (peak memory bound = 1.51 x predicted peak memory)', $text);
        self::assertStringContainsString('marked  GET /b  (bound 576.0 MiB, 112.5% of the limit)', $text);
        self::assertStringContainsString('not judged (no peak-memory estimate): GET /u', $text);
    }

    public function testTheNoteOnTheLimitRpsIsPrintedOnceWhenBothSidesMarkARoute(): void
    {
        $text = PredictionText::render(self::report(self::LIMIT, 0.5));
        self::assertSame(1, substr_count($text, 'NOTE ' . AppLimitRisk::LIMIT_RPS_NOTE));
        $memoryOnly = PredictionText::render(self::report(self::LIMIT));
        self::assertSame(1, substr_count($memoryOnly, 'NOTE ' . AppLimitRisk::LIMIT_RPS_NOTE));
    }

    public function testWhatTheRecordsSayAboutTheFormulaDoesNotSwitchTheMarkOff(): void
    {
        $within = self::report(self::LIMIT, null, MemoryAccuracy::fromArray(['schema_version' => '1', 'source' => 'test', 'within' => ['GET /a', 'GET /b']]));
        $outside = self::report(self::LIMIT, null, MemoryAccuracy::fromArray(['schema_version' => '1', 'source' => 'test', 'outside' => ['GET /a', 'GET /b']]));
        $none = self::report(self::LIMIT, null, MemoryAccuracy::none());

        self::assertSame(['GET /b'], $within['scenarios'][0]['app_limit_risk_memory']['marked_routes']);
        self::assertSame(['GET /b'], $outside['scenarios'][0]['app_limit_risk_memory']['marked_routes']);
        self::assertSame(['GET /b'], $none['scenarios'][0]['app_limit_risk_memory']['marked_routes']);
        // the count N of section 6.2 is still shown only for a formula that held
        self::assertSame('shown', array_column($within['peak_memory']['routes'], 'status', 'route')['GET /b']);
        self::assertSame('not_shown', array_column($outside['peak_memory']['routes'], 'status', 'route')['GET /b']);
        self::assertSame('not_shown', array_column($none['peak_memory']['routes'], 'status', 'route')['GET /b']);
    }
}
