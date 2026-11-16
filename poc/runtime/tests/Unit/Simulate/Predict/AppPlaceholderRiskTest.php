<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Simulate\Predict;

use PeakSim\Runtime\Simulate\Predict\AccuracyRecord;
use PeakSim\Runtime\Simulate\Predict\AppLimitRisk;
use PeakSim\Runtime\Simulate\Predict\AppPlaceholderRisk;
use PeakSim\Runtime\Simulate\Predict\MemoryAccuracy;
use PeakSim\Runtime\Simulate\Predict\PredictionReport;
use PeakSim\Runtime\Simulate\Predict\PredictionText;
use PHPUnit\Framework\TestCase;

/**
 * The placeholder side of the `app_limit_risk` mark on made-up routes. A statement carries `fixed + (values - fixed) x n / scale` values at the table
 * size n (rounded up); a route is marked when its largest statement carries more than the limit. The limit of most tests is 65,535. The expected values are
 * worked out by hand below.
 */
final class AppPlaceholderRiskTest extends TestCase
{
    private const LIMIT = 65535;

    /**
     * A route whose one statement type has the figure `placeholders` at the table size `n`.
     *
     * @return array<string, mixed>
     */
    private static function route(string $name, int $n, int $scale, int $values, int $fixed, string $id = 'sX'): array
    {
        return PredictFixtures::route($name, 1.0, 5.0, 1000.0, null, ['types' => [
            ['id' => 'sSmall', 'kind' => 'select', 'pattern' => 'select 1', 'count' => 1.0, 'per_exec' => 1.0, 'per_exec_upper' => null, 'marks' => [], 'rows' => 1.0, 'rows_upper' => 1.0,
                'placeholders' => ['scale' => $scale, 'values' => 3, 'fixed' => 3, 'at' => $n]],
            ['id' => $id, 'kind' => 'select', 'pattern' => 'select 2', 'count' => 1.0, 'per_exec' => 1.0, 'per_exec_upper' => null, 'marks' => [], 'rows' => 1.0, 'rows_upper' => 1.0,
                'placeholders' => ['scale' => $scale, 'values' => $values, 'fixed' => $fixed, 'at' => $n]],
        ]]);
    }

    /** @return array<string, string> route => status */
    private static function statuses(array $risk): array
    {
        return array_column($risk['routes'], 'status', 'route');
    }

    public function testTheValuesAtASizeAreTheFixedPartPlusTheListPartScaledByTheSize(): void
    {
        // 18,004 values at 30,000 rows, 4 of them fixed: 4 + 18,000 x n / 30,000
        $p = ['scale' => 30000, 'values' => 18004, 'fixed' => 4];
        self::assertSame(18004.0, AppPlaceholderRisk::valuesAt($p, 30000));
        self::assertSame(6004.0, AppPlaceholderRisk::valuesAt($p, 10000));
        self::assertSame(60004.0, AppPlaceholderRisk::valuesAt($p, 100000));
        self::assertSame(120004.0, AppPlaceholderRisk::valuesAt($p, 200000));
        self::assertSame(180004.0, AppPlaceholderRisk::valuesAt($p, 300000));
        // rounded up: 1 fixed + 600 x 100 / 30,000 = 3
        self::assertSame(3.0, AppPlaceholderRisk::valuesAt(['scale' => 30000, 'values' => 601, 'fixed' => 1], 100));
        // 1 fixed + 600 x 101 / 30,000 = 3.02, up to 4
        self::assertSame(4.0, AppPlaceholderRisk::valuesAt(['scale' => 30000, 'values' => 601, 'fixed' => 1], 101));
    }

    public function testARouteOverTheLimitIsMarkedAndOneUnderItIsNot(): void
    {
        $routes = [
            self::route('GET /heavy', 300000, 30000, 18004, 4),   // 180,004
            self::route('GET /light', 300000, 30000, 18, 18),     // 18 (all fixed)
            self::route('GET /under', 100000, 30000, 18004, 4),   // 60,004
            self::route('GET /grows', 300000, 30000, 100, 0),     // 1,000
        ];
        $risk = AppPlaceholderRisk::evaluate($routes, self::LIMIT);

        self::assertSame(
            ['GET /heavy' => 'marked', 'GET /light' => 'not_marked', 'GET /under' => 'not_marked', 'GET /grows' => 'not_marked'],
            self::statuses($risk),
        );
        self::assertSame(['GET /heavy'], $risk['marked_routes']);
        self::assertSame(self::LIMIT, $risk['limit_values']);
        self::assertSame('placeholders', $risk['limit_kind']);
        self::assertSame(180004.0, $risk['routes'][0]['values']);
        self::assertSame('sX', $risk['routes'][0]['statement']);
        self::assertEqualsWithDelta(180004.0 / self::LIMIT, $risk['routes'][0]['share_of_limit'], 1e-12);
        self::assertSame(0, $risk['routes'][0]['types_without_figure']);
        self::assertNull($risk['routes'][0]['reason']);
    }

    public function testTheMarkFollowsTheLimitThatWasGiven(): void
    {
        $routes = [self::route('GET /a', 300000, 30000, 18004, 4), self::route('GET /b', 100000, 30000, 18004, 4)];
        // 180,004 and 60,004 values
        self::assertSame(['GET /a'], AppPlaceholderRisk::evaluate($routes, 65535)['marked_routes']);
        self::assertSame(['GET /a', 'GET /b'], AppPlaceholderRisk::evaluate($routes, 60000)['marked_routes']);
        self::assertSame([], AppPlaceholderRisk::evaluate($routes, 200000)['marked_routes']);
        self::assertSame(['GET /a'], AppPlaceholderRisk::evaluate($routes, 100000)['marked_routes']);
    }

    public function testAStatementThatCarriesExactlyTheLimitIsNotMarkedAndOneMoreIs(): void
    {
        // 65,535 fixed values: the database accepts it; 65,536 it refuses
        self::assertSame([], AppPlaceholderRisk::evaluate([self::route('GET /x', 1000, 1000, 65535, 65535)], self::LIMIT)['marked_routes']);
        self::assertSame(['GET /x'], AppPlaceholderRisk::evaluate([self::route('GET /x', 1000, 1000, 65536, 65536)], self::LIMIT)['marked_routes']);
    }

    public function testTheLargestStatementOfTheRouteDecides(): void
    {
        $risk = AppPlaceholderRisk::evaluate([self::route('GET /x', 300000, 30000, 18004, 4, 'sBig')], self::LIMIT);
        self::assertSame('sBig', $risk['routes'][0]['statement']);
    }

    public function testARouteWithNoFigureIsNotJudgedAndIsNeverMarked(): void
    {
        $routes = [
            PredictFixtures::route('GET /no-flag', 1.0, 5.0, 1000.0, null, ['types' => [['id' => 'sA', 'kind' => 'select', 'pattern' => 'select 1', 'count' => 1.0, 'per_exec' => 1.0, 'per_exec_upper' => null, 'marks' => [], 'rows' => 1.0, 'rows_upper' => 1.0]]]),
            PredictFixtures::route('GET /no-types', 1.0, 5.0, 1000.0),
            PredictFixtures::unknown('GET /unknown', 3.0),
            self::route('GET /heavy', 300000, 30000, 18004, 4),
        ];
        $risk = AppPlaceholderRisk::evaluate($routes, self::LIMIT);
        self::assertSame(
            ['GET /no-flag' => 'not_evaluated', 'GET /no-types' => 'not_evaluated', 'GET /unknown' => 'not_evaluated', 'GET /heavy' => 'marked'],
            self::statuses($risk),
        );
        self::assertSame(['no_placeholder_figure', 'no_placeholder_figure', 'no_cost', null], array_column($risk['routes'], 'reason'));
        self::assertSame([1, 0, 0, 0], array_column($risk['routes'], 'types_without_figure'));
        self::assertNull($risk['routes'][0]['values']);
        self::assertSame(['GET /heavy'], $risk['marked_routes']);
    }

    public function testARouteWithSomeFiguresIsJudgedOnThemAndSaysHowManyTypesHadNone(): void
    {
        $route = self::route('GET /x', 300000, 30000, 18004, 4);
        $route['types'][] = ['id' => 'sNone', 'kind' => 'select', 'pattern' => 'select 3', 'count' => 1.0, 'per_exec' => 1.0, 'per_exec_upper' => null, 'marks' => [], 'rows' => 1.0, 'rows_upper' => 1.0];
        $risk = AppPlaceholderRisk::evaluate([$route], self::LIMIT);
        self::assertSame('marked', $risk['routes'][0]['status']);
        self::assertSame(1, $risk['routes'][0]['types_without_figure']);
    }

    public function testTheMarkDoesNotDependOnTheRps(): void
    {
        $routes = [self::route('GET /a', 300000, 30000, 18004, 4), self::route('GET /b', 100000, 30000, 18004, 4)];
        $routes[0]['rps'] = 0.001;
        $routes[1]['rps'] = 500.0;
        self::assertSame(['GET /a'], AppPlaceholderRisk::evaluate($routes, self::LIMIT)['marked_routes']);
    }

    public function testTheLimitRpsNoteIsThereOnlyWhenARouteIsMarked(): void
    {
        $routes = [self::route('GET /a', 300000, 30000, 18004, 4)];
        self::assertSame(AppLimitRisk::LIMIT_RPS_NOTE, AppPlaceholderRisk::evaluate($routes, self::LIMIT)['limit_rps_note']);
        self::assertNull(AppPlaceholderRisk::evaluate($routes, 1000000)['limit_rps_note']);
    }

    public function testALimitOfZeroOrLessIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AppPlaceholderRisk::evaluate([self::route('GET /a', 1, 1, 1, 1)], 0);
    }

    public function testTheRuleOnTheRecordsOfTheLabPicksTheRoutesThatFailedAndNoRouteElse(): void
    {
        $file = dirname(__DIR__, 6) . '/docs/research/lab-data/diagnosis/app-placeholder-risk/rule.json';
        self::assertFileExists($file);
        $record = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(65535, $record['limit_values_of_the_database']);
        foreach ($record['judged_on_the_lab_records'] as $cell) {
            $routes = [];
            foreach ($record['statements'] as $s) {
                if ($s['app'] !== $cell['app']) {
                    continue;
                }
                $routes[$s['route']]['types'][] = ['id' => $s['id'], 'placeholders' => ['scale' => $s['scale'], 'values' => $s['values'], 'fixed' => $s['fixed'], 'at' => $cell['rows']]];
            }
            $list = [];
            foreach ($routes as $name => $r) {
                $list[] = PredictFixtures::route((string) $name, 1.0, 5.0, 1000.0, null, ['types' => $r['types']]);
            }
            $risk = AppPlaceholderRisk::evaluate($list, $record['limit_values_of_the_database']);
            self::assertSame($cell['marked_routes'], $risk['marked_routes'], $cell['app'] . ' at ' . $cell['rows'] . ' rows: the tool and the script agree');
            self::assertNotSame('miss', $cell['verdict'] ?? null);
            self::assertNotSame('false_alarm', $cell['verdict'] ?? null);
            self::assertSame([], $cell['marked_routes_with_no_failure_on_record']);
        }
    }

    // ---- the whole report -------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> the loaded input with one route that has a figure at the target size, an optional placeholder limit and an optional execution limit */
    private static function loaded(?int $placeholderLimit, ?float $cpuLimit = null): array
    {
        $routes = [
            self::route('GET /a', 100000, 30000, 18004, 4),
            self::route('GET /b', 300000, 30000, 18004, 4),
            PredictFixtures::unknown('GET /u', 1.0),
        ];
        $resources = ['cores' => 2, 'threshold' => 0.8, 'php_memory_limit_bytes' => 134217728];
        if ($placeholderLimit !== null) {
            $resources['db_placeholder_limit'] = $placeholderLimit;
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
    private static function report(?int $placeholderLimit, ?float $cpuLimit = null): array
    {
        $memory = ['GET /a' => ['table' => 'items', 'a' => 0.0, 'b' => 1000.0, 'k' => 1.0]];
        $acc = MemoryAccuracy::fromArray(['schema_version' => '1', 'source' => 'test', 'within' => ['GET /a']]);

        return PredictionReport::build(self::loaded($placeholderLimit, $cpuLimit), PredictFixtures::twoCoefficient(0.001, 0.0001, 0.0005, 0.0002), $memory, $acc, AccuracyRecord::notChecked());
    }

    private static function json(array $report): string
    {
        return json_encode($report, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /** The bytes of the output with no limit at all are fixed by the golden in AppLimitRiskTest; this one fixes that the placeholder side leaves no key and no line. */
    public function testWithNoPlaceholderLimitGivenTheOutputHasNoTraceOfTheMark(): void
    {
        $report = self::report(null);
        self::assertStringNotContainsString('app_limit_risk_placeholders', self::json($report));
        self::assertStringNotContainsString('db_placeholder_limit', self::json($report));
        self::assertStringNotContainsString('values in one statement', PredictionText::render($report));
    }

    public function testAGivenLimitAddsTheBlockToEveryTargetScenarioAndNothingElseChanges(): void
    {
        $without = self::report(null);
        $with = self::report(self::LIMIT);
        $risk = $with['scenarios'][0]['app_limit_risk_placeholders'];
        // the figure is read at the route's own table size: 60,004 values for 'GET /a' (100,000 rows), 180,004 for 'GET /b' (300,000 rows)
        self::assertSame(['GET /a' => 'not_marked', 'GET /b' => 'marked', 'GET /u' => 'not_evaluated'], self::statuses($risk));
        self::assertSame(self::LIMIT, $with['resources']['db_placeholder_limit']);

        unset($with['scenarios'][0]['app_limit_risk_placeholders'], $with['resources']['db_placeholder_limit']);
        self::assertSame($without, $with, 'the utilisation, the limit multiplier and everything else are the same with and without the placeholder limit');
    }

    public function testTheOutputSaysWhichLimitMarkedTheRoute(): void
    {
        $text = PredictionText::render(self::report(self::LIMIT, 2.0));
        self::assertStringContainsString('app limit    2 s of cpu time: 0 route(s) marked app_limit_risk', $text);
        self::assertStringContainsString('app limit    65,535 values in one statement: 1 route(s) marked app_limit_risk', $text);
        self::assertStringContainsString('marked  GET /b  (180,004 values in statement sX, 274.7% of the limit)', $text);
        self::assertStringContainsString('not judged (no cost or no placeholder figure in the estimate): GET /u', $text);
    }

    public function testTheNoteOnTheLimitRpsIsPrintedOnceWhenTwoSidesMarkARoute(): void
    {
        $text = PredictionText::render(self::report(self::LIMIT, 0.0001));
        self::assertSame(1, substr_count($text, 'NOTE ' . AppLimitRisk::LIMIT_RPS_NOTE));
    }
}
