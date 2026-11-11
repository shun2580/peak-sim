<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Simulate\Predict;

use PeakSim\Runtime\Simulate\Predict\AccuracyRecord;
use PeakSim\Runtime\Simulate\Predict\AppLimitRisk;
use PeakSim\Runtime\Simulate\Predict\Coefficients;
use PeakSim\Runtime\Simulate\Predict\MemoryAccuracy;
use PeakSim\Runtime\Simulate\Predict\PredictionReport;
use PeakSim\Runtime\Simulate\Predict\PredictionText;
use PHPUnit\Framework\TestCase;

/**
 * The `app_limit_risk` mark on made-up routes. With c_q = 0.001 s and c_row = 0.0001 s, the converted database time of one request is
 * queries x 0.001 + rows x 0.0001 seconds, worked out by hand below; the factor is AppLimitRisk::PHP_CPU_OVER_DB (9.11).
 */
final class AppLimitRiskTest extends TestCase
{
    private static function coefficients(): Coefficients
    {
        return new Coefficients('two_coefficient', 0.001, 0.0001);
    }

    /**
     * 'GET /heavy'  100 queries, 200,000 rows -> 0.1 + 20 = 20.1 s    x 9.11 = 183.111 s
     * 'GET /light'  5 queries, 1,000 rows     -> 0.005 + 0.1 = 0.105 s x 9.11 = 0.95655 s
     * 'GET /edge'   5 queries, 32,000 rows    -> 0.005 + 3.2 = 3.205 s x 9.11 = 29.19755 s (just under 30)
     *
     * @return list<array<string, mixed>>
     */
    private static function routes(): array
    {
        return [
            PredictFixtures::route('GET /heavy', 1.0, 100.0, 200000.0),
            PredictFixtures::route('GET /light', 1.0, 5.0, 1000.0),
            PredictFixtures::route('GET /edge', 1.0, 5.0, 32000.0),
        ];
    }

    /** @return array<string, string> route => status */
    private static function statuses(array $risk): array
    {
        return array_column($risk['routes'], 'status', 'route');
    }

    public function testARouteOverTheLimitIsMarkedAndOneUnderItIsNot(): void
    {
        $risk = AppLimitRisk::evaluate(self::routes(), self::coefficients(), 30.0);

        self::assertSame(
            ['GET /heavy' => AppLimitRisk::MARKED, 'GET /light' => AppLimitRisk::NOT_MARKED, 'GET /edge' => AppLimitRisk::NOT_MARKED],
            self::statuses($risk),
        );
        self::assertSame(['GET /heavy'], $risk['marked_routes']);
        self::assertEqualsWithDelta(183.111, $risk['routes'][0]['php_cpu_bound_s'], 1e-9);
        self::assertEqualsWithDelta(20.1, $risk['routes'][0]['db_time_s']['point'], 1e-9);
        self::assertEqualsWithDelta(0.95655, $risk['routes'][1]['php_cpu_bound_s'], 1e-9);
        self::assertEqualsWithDelta(29.19755 / 30.0, $risk['routes'][2]['share_of_limit'], 1e-9);
    }

    public function testTheMarkFollowsTheLimitThatWasGiven(): void
    {
        self::assertSame(['GET /heavy'], AppLimitRisk::evaluate(self::routes(), self::coefficients(), 30.0)['marked_routes']);
        self::assertSame(['GET /heavy', 'GET /edge'], AppLimitRisk::evaluate(self::routes(), self::coefficients(), 29.0)['marked_routes']);
        self::assertSame([], AppLimitRisk::evaluate(self::routes(), self::coefficients(), 200.0)['marked_routes']);
        self::assertSame(['GET /heavy', 'GET /light', 'GET /edge'], AppLimitRisk::evaluate(self::routes(), self::coefficients(), 0.95)['marked_routes']);
    }

    public function testTheLargerOfThePointAndTheSafeSideIsUsed(): void
    {
        // point 5 queries and 1,000 rows (0.105 s, bound 0.957 s); the safe side has 60,000 rows (0.005 + 6 = 6.005 s, bound 54.7 s)
        $risk = AppLimitRisk::evaluate([PredictFixtures::route('GET /m', 1.0, 5.0, 1000.0, 60000.0)], self::coefficients(), 30.0);
        self::assertSame(['GET /m'], $risk['marked_routes']);
        self::assertEqualsWithDelta(0.105, $risk['routes'][0]['db_time_s']['point'], 1e-9);
        self::assertEqualsWithDelta(6.005, $risk['routes'][0]['db_time_s']['upper'], 1e-9);
    }

    public function testTheMarkDoesNotDependOnTheRps(): void
    {
        $routes = self::routes();
        $routes[0]['rps'] = 0.001;
        $routes[1]['rps'] = 500.0;
        self::assertSame(['GET /heavy'], AppLimitRisk::evaluate($routes, self::coefficients(), 30.0)['marked_routes']);
    }

    public function testARouteWithNoCostIsNotJudgedAndIsNeverMarked(): void
    {
        $risk = AppLimitRisk::evaluate([PredictFixtures::unknown('GET /u', 3.0)], self::coefficients(), 30.0);
        self::assertSame(['GET /u' => AppLimitRisk::NOT_EVALUATED], self::statuses($risk));
        self::assertNull($risk['routes'][0]['php_cpu_bound_s']);
        self::assertSame([], $risk['marked_routes']);
        self::assertNull($risk['limit_rps_note']);
    }

    public function testTheLimitRpsNoteIsThereOnlyWhenARouteIsMarked(): void
    {
        self::assertSame(AppLimitRisk::LIMIT_RPS_NOTE, AppLimitRisk::evaluate(self::routes(), self::coefficients(), 30.0)['limit_rps_note']);
        self::assertNull(AppLimitRisk::evaluate(self::routes(), self::coefficients(), 200.0)['limit_rps_note']);
    }

    public function testALimitOfZeroOrLessIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AppLimitRisk::evaluate(self::routes(), self::coefficients(), 0.0);
    }

    public function testTheFactorIsTheOneTheRecordsGive(): void
    {
        $file = dirname(__DIR__, 6) . '/docs/research/lab-data/diagnosis/app-limit-risk/factor.json';
        self::assertFileExists($file);
        $record = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($record['factor'], AppLimitRisk::PHP_CPU_OVER_DB);
    }

    // ---- the whole report -------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> the loaded input of the report test's two routes, with an optional limit */
    private static function loaded(?float $limit): array
    {
        $routes = [PredictFixtures::route('GET /a', 10.0, 5.0, 1000.0), PredictFixtures::route('GET /b', 5.0, 2.0, 400.0, 4000.0), PredictFixtures::unknown('GET /u', 1.0)];
        $resources = ['cores' => 2, 'threshold' => 0.8, 'php_memory_limit_bytes' => 134217728];
        if ($limit !== null) {
            $resources['app_cpu_time_limit_s'] = $limit;
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
    private static function report(?float $limit): array
    {
        $memory = ['GET /a' => ['table' => 'items', 'a' => 0.0, 'b' => 1000.0, 'k' => 1.0], 'GET /b' => null];
        $acc = MemoryAccuracy::fromArray(['schema_version' => '1', 'source' => 'test', 'within' => ['GET /a']]);

        return PredictionReport::build(self::loaded($limit), PredictFixtures::twoCoefficient(0.001, 0.0001, 0.0005, 0.0002), $memory, $acc, AccuracyRecord::notChecked());
    }

    private static function json(array $report): string
    {
        return json_encode($report, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    public function testWithNoLimitGivenTheOutputIsTheSameBytesAsBeforeTheMarkExisted(): void
    {
        $report = self::report(null);
        self::assertSame((string) file_get_contents(__DIR__ . '/golden/report-no-limit.json'), self::json($report));
        self::assertSame((string) file_get_contents(__DIR__ . '/golden/report-no-limit.txt'), PredictionText::render($report));
        self::assertStringNotContainsString('app_limit_risk', self::json($report));
        self::assertStringNotContainsString('app limit', PredictionText::render($report));
    }

    public function testAGivenLimitAddsTheMarkToEveryTargetScenarioAndNothingElseChanges(): void
    {
        $without = self::report(null);
        // 'GET /a': 5 queries, 1,000 rows -> 0.105 s, bound 0.957 s (under 2 s); 'GET /b': 2 queries, safe side 4,000 rows -> 0.402 s, bound 3.66 s (over)
        $with = self::report(2.0);
        $risk = $with['scenarios'][0]['app_limit_risk'];
        self::assertSame(['GET /a' => AppLimitRisk::NOT_MARKED, 'GET /b' => AppLimitRisk::MARKED, 'GET /u' => AppLimitRisk::NOT_EVALUATED], self::statuses($risk));
        self::assertSame(2.0, $with['resources']['app_cpu_time_limit_s']);

        unset($with['scenarios'][0]['app_limit_risk'], $with['resources']['app_cpu_time_limit_s']);
        self::assertSame($without, $with, 'the utilisation, the limit multiplier and everything else are the same with and without the limit');
    }

    public function testTheTextSaysHowManyAreMarkedAndThatTheLimitCountsThemAsRunningToTheEnd(): void
    {
        $text = PredictionText::render(self::report(2.0));
        self::assertStringContainsString('app limit    2 s of cpu time: 1 route(s) marked app_limit_risk', $text);
        self::assertStringContainsString('marked  GET /b', $text);
        self::assertStringContainsString('NOTE ' . AppLimitRisk::LIMIT_RPS_NOTE, $text);
        self::assertStringContainsString('not judged (no cost): GET /u', $text);

        $none = PredictionText::render(self::report(1000.0));
        self::assertStringContainsString('0 route(s) marked app_limit_risk', $none);
        self::assertStringNotContainsString('NOTE ' . AppLimitRisk::LIMIT_RPS_NOTE, $none);
    }
}
