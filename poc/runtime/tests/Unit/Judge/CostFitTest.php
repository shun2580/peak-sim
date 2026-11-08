<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Judge;

use PeakSim\Runtime\Judge\CostFit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The expected values here are the formulas the series were MADE from: a series is built from a known a, b and k,
 * the fit is asked for them back, and they must come back. Nothing below is read off a run of CostFit.
 */
final class CostFitTest extends TestCase
{
    private const SCALES = [0, 100, 1000, 10000];

    /** @return list<float> y = a + b * N^k at each scale (N = 0 gives a, and a + b when k = 0) */
    private static function series(float $a, float $b, float $k, array $scales = self::SCALES): array
    {
        return array_map(static fn (int $n): float => $a + $b * ($n === 0 ? ($k === 0.0 ? 1.0 : 0.0) : $n ** $k), $scales);
    }

    /** @return array<string, array{float, float, float}> */
    public static function knownFormulas(): array
    {
        return [
            'linear with a fixed part (queries: 4 + N)' => [4.0, 1.0, 1.0],
            'linear, a = 0 (rows examined: 3 N)' => [0.0, 3.0, 1.0],
            'quadratic with a fixed part' => [10.0, 0.5, 2.0],
            'quadratic, a = 0' => [0.0, 0.002, 2.0],
            'k = 1.5, a = 0' => [0.0, 0.25, 1.5],
            'k = 0.5 with a fixed part' => [100.0, 7.0, 0.5],
            'cubic' => [2.0, 0.000001, 3.0],
            'a small slope on a big fixed part (peak memory)' => [20000000.0, 5000.0, 1.0],
        ];
    }

    #[DataProvider('knownFormulas')]
    public function testItGivesBackTheAbkTheSeriesWasMadeFrom(float $a, float $b, float $k): void
    {
        $fit = CostFit::fit(self::SCALES, self::series($a, $b, $k));

        self::assertEqualsWithDelta($k, $fit['k'], 1e-9);
        self::assertEqualsWithDelta($a, $fit['a'], max(1e-6, abs($a) * 1e-9));
        self::assertEqualsWithDelta($b, $fit['b'], max(1e-12, abs($b) * 1e-6));
        self::assertEqualsWithDelta(0.0, $fit['sse'], 1e-6);
    }

    public function testAConstantSeriesIsKZeroAndOnlyTheSumOfAAndBIsKnown(): void
    {
        // y = 5 + 3 * N^0 = 8 at every scale. a and b cannot be told apart; the constant is reported as `a`.
        $fit = CostFit::fit(self::SCALES, self::series(5.0, 3.0, 0.0));

        self::assertSame(0.0, $fit['k']);
        self::assertEqualsWithDelta(8.0, $fit['a'] + $fit['b'], 1e-9);
        self::assertEqualsWithDelta(0.0, $fit['sse'], 1e-9);

        $pure = CostFit::fit(self::SCALES, [4.0, 4.0, 4.0, 4.0]);
        self::assertSame(0.0, $pure['k']);
        self::assertEqualsWithDelta(4.0, $pure['a'] + $pure['b'], 1e-9);
    }

    public function testAllZerosIsZeroEverywhere(): void
    {
        $fit = CostFit::fit(self::SCALES, [0, 0, 0, 0]);

        self::assertSame(['a' => 0.0, 'b' => 0.0, 'k' => 0.0, 'sse' => 0.0], $fit);
    }

    public function testItWorksWithThreeScalesAndWithMoreThanFour(): void
    {
        $three = [0, 100, 1000];
        $fit = CostFit::fit($three, self::series(4.0, 1.0, 1.0, $three));
        self::assertEqualsWithDelta(1.0, $fit['k'], 1e-9);
        self::assertEqualsWithDelta(4.0, $fit['a'], 1e-9);

        $five = [0, 100, 1000, 10000, 100000];
        $fit = CostFit::fit($five, self::series(0.0, 0.02, 2.0, $five));
        self::assertEqualsWithDelta(2.0, $fit['k'], 1e-9);
        self::assertEqualsWithDelta(0.0, $fit['a'], 1e-6);
        self::assertEqualsWithDelta(0.02, $fit['b'], 1e-9);
    }

    public function testTheOrderOfTheInputDoesNotMatter(): void
    {
        $scales = [10000, 0, 1000, 100];
        $values = self::series(4.0, 1.0, 1.0, $scales);

        self::assertEquals(CostFit::fit(self::SCALES, self::series(4.0, 1.0, 1.0)), CostFit::fit($scales, $values));
    }

    public function testASeriesBetweenGridStepsPicksTheNearestStepAndNeverGoesBelowZero(): void
    {
        // k = 1.234 is not on the 0.01 grid: the nearest steps are 1.23 and 1.24. The coefficients stay non-negative.
        $fit = CostFit::fit(self::SCALES, self::series(3.0, 0.8, 1.234));

        self::assertEqualsWithDelta(1.234, $fit['k'], 0.0051);
        self::assertGreaterThanOrEqual(0.0, $fit['a']);
        self::assertGreaterThanOrEqual(0.0, $fit['b']);
    }

    public function testANegativeCoefficientIsCutToZero(): void
    {
        // Values that fall as N grows would want b < 0: b is cut to 0 and a is the mean.
        $falling = CostFit::fit(self::SCALES, [10.0, 8.0, 6.0, 4.0]);
        self::assertSame(0.0, $falling['b']);
        self::assertEqualsWithDelta(7.0, $falling['a'], 1e-9);

        // y = N exactly except that the empty system is 0 is a = 0, never a negative a with a larger b.
        $zero = CostFit::fit([0, 100, 1000], [0.0, 100.0, 1000.0]);
        self::assertSame(0.0, $zero['a']);
        self::assertEqualsWithDelta(1.0, $zero['b'], 1e-9);
    }

    public function testTheSameInputGivesTheSameOutput(): void
    {
        $values = [3.0, 17.0, 211.0, 2600.0];

        self::assertSame(CostFit::fit(self::SCALES, $values), CostFit::fit(self::SCALES, $values));
    }

    public function testASeriesNeedsOneValuePerScale(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CostFit::fit([0, 100], [1.0]);
    }

    public function testEvaluateIsTheFormula(): void
    {
        self::assertEqualsWithDelta(4.0 + 1.0 * 100000.0, CostFit::evaluate(4.0, 1.0, 1.0, 100000), 1e-9);
        self::assertEqualsWithDelta(10.0 + 0.5 * 1.0e10, CostFit::evaluate(10.0, 0.5, 2.0, 100000), 1e-3);
        self::assertSame(7.0, CostFit::evaluate(7.0, 3.0, 2.0, 0));
    }

    /** @return array<string, array{float, float, float}> */
    public static function pairFormulas(): array
    {
        return [
            'k = 1' => [4.0, 1.0, 1.0],
            'k = 2' => [10.0, 0.5, 2.0],
            'a = 0, k = 1' => [0.0, 3.0, 1.0],
        ];
    }

    #[DataProvider('pairFormulas')]
    public function testEveryPairOfScalesGivesBackBAndKWhenAIsFixedAtTheRightValue(float $a, float $b, float $k): void
    {
        $pairs = CostFit::pairs(self::SCALES, self::series($a, $b, $k), $a);

        self::assertSame([[100, 1000], [100, 10000], [1000, 10000]], array_column($pairs, 'scales'));
        foreach ($pairs as $pair) {
            self::assertEqualsWithDelta($k, $pair['k'], 1e-9);
            self::assertEqualsWithDelta($b, $pair['b'], abs($b) * 1e-6);
        }
    }

    public function testThePairsKeepAFixedSoTheTwoPointsDecideOnlyBAndK(): void
    {
        // The full-sweep a is 4 here. Pair (1000, 10000) of y = 4 + N has no slack: with a = 4 it gives b = 1, k = 1.
        $pairs = CostFit::pairs(self::SCALES, self::series(4.0, 1.0, 1.0), 4.0);
        self::assertEqualsWithDelta(1.0, $pairs[2]['b'], 1e-9);

        // With a held at 0 instead, the same two points are read as y = b N^k with no fixed part: k comes out below 1.
        $held = CostFit::pairs(self::SCALES, self::series(4.0, 1.0, 1.0), 0.0);
        self::assertLessThan(1.0, $held[0]['k']);
    }

    public function testScaleZeroIsNotOneOfThePairedScales(): void
    {
        $pairs = CostFit::pairs([0, 100, 1000], [4.0, 104.0, 1004.0], 4.0);

        self::assertCount(1, $pairs);
        self::assertSame([100, 1000], $pairs[0]['scales']);
    }

    // ---------------------------------------------------------------- a quantity of a route

    public function testAQuantityWithEnoughScalesIsOkAndCarriesThePairs(): void
    {
        $q = CostFit::quantity(
            [0 => 4, 100 => 104, 1000 => 1004, 10000 => 10004],
            [],
            [0, 100, 1000, 10000],
            true,
            'O(N)',
        );

        self::assertSame('ok', $q['fit_status']);
        self::assertEqualsWithDelta(4.0, $q['a'], 1e-9);
        self::assertEqualsWithDelta(1.0, $q['b'], 1e-9);
        self::assertEqualsWithDelta(1.0, $q['k'], 1e-9);
        self::assertSame([0, 100, 1000, 10000], $q['scales']);
        self::assertCount(3, $q['pairs']);
    }

    public function testAFlatQueryCountUnderAnLinearStaticBoundIsRefusedNotAdopted(): void
    {
        $series = [0 => 4, 100 => 4, 1000 => 4, 10000 => 4];

        $q = CostFit::quantity($series, [], [0, 100, 1000, 10000], true, 'O(N)');

        self::assertSame('contradicts_static', $q['fit_status']);
        self::assertArrayNotHasKey('a', $q);
        self::assertArrayNotHasKey('k', $q);
        self::assertStringContainsString('O(N)', $q['reason']);
        self::assertSame(0.0, $q['rejected_fit']['k']);
    }

    public function testTheContradictionLineIsKTwoTenths(): void
    {
        // Just below and just above the line, from series made with k = 0.2 and k = 0.3.
        $scales = [0, 100, 1000, 10000];
        $at = static fn (float $k): array => array_combine($scales, array_map(static fn (int $n): float => 3.0 + 2.0 * ($n === 0 ? 0.0 : $n ** $k), $scales));

        self::assertSame('contradicts_static', CostFit::quantity($at(0.2), [], $scales, true, 'O(N)')['fit_status']);
        self::assertSame('ok', CostFit::quantity($at(0.3), [], $scales, true, 'O(N)')['fit_status']);
    }

    public function testTheContradictionCheckOnlyAppliesToQueryCountsWithALinearBound(): void
    {
        $flat = [0 => 4, 100 => 4, 1000 => 4, 10000 => 4];
        $scales = [0, 100, 1000, 10000];

        self::assertSame('ok', CostFit::quantity($flat, [], $scales, false, 'O(N)')['fit_status'], 'not a query count');
        self::assertSame('ok', CostFit::quantity($flat, [], $scales, true, null)['fit_status'], 'no static bound');
        self::assertSame('ok', CostFit::quantity($flat, [], $scales, true, 'O(1)')['fit_status'], 'a bound of O(1) forbids nothing');
        self::assertSame('contradicts_static', CostFit::quantity($flat, [], $scales, true, 'O(N*M)')['fit_status']);
    }

    public function testAScaleThatCouldNotBeMeasuredMakesNoFormulaAndSaysWhy(): void
    {
        $q = CostFit::quantity([0 => 4, 100 => 104, 1000 => 1004], [10000 => 'timeout'], [0, 100, 1000, 10000], true, null);

        self::assertSame('insufficient_scales', $q['fit_status']);
        self::assertSame('scale 10000: timeout', $q['reason']);
        self::assertArrayNotHasKey('a', $q);
    }

    public function testAScaleWithNoAttemptAtAllIsAlsoNamed(): void
    {
        $q = CostFit::quantity([0 => 4, 100 => 104, 1000 => 1004], [], [0, 100, 1000, 10000], true, null);

        self::assertSame('insufficient_scales', $q['fit_status']);
        self::assertSame('scale 10000: not measured', $q['reason']);
    }

    public function testFewerThanThreeScalesCountingScaleZeroIsInsufficient(): void
    {
        $q = CostFit::quantity([0 => 4, 100 => 104], [], [0, 100], true, null);

        self::assertSame('insufficient_scales', $q['fit_status']);
        self::assertStringContainsString('2 scales', $q['reason']);
    }

    public function testEveryFailedScaleIsListed(): void
    {
        $q = CostFit::quantity([0 => 4, 100 => 104], [1000 => 'timeout', 10000 => 'timeout'], [0, 100, 1000, 10000], true, null);

        self::assertSame('scale 1000: timeout; scale 10000: timeout', $q['reason']);
    }

    // ---------------------------------------------------------------- fits.json from a record

    /** @param array<string, mixed> $over */
    private static function attempt(string $route, int $scale, array $over = []): array
    {
        return $over + [
            'route' => $route, 'method' => 'GET', 'scale' => $scale, 'axis' => 'N', 'attempt' => 2, 'class' => 'ok',
            'queries' => 4 + $scale, 'rows_examined' => 2 * $scale, 'peak_mem' => 1000000,
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function sweep(string $route, array $over = []): array
    {
        return array_map(static fn (int $s): array => self::attempt($route, $s, $over), [0, 100, 1000, 10000]);
    }

    public function testFromRecordsMakesTheFileInTheSpecifiedShape(): void
    {
        $attempts = [
            ...self::sweep('books/{slug}'),
            // a warmup and a warm attempt of the same route are not the cold value and are ignored
            self::attempt('books/{slug}', 100, ['attempt' => 1, 'queries' => 9999]),
            self::attempt('books/{slug}', 100, ['attempt' => 3, 'queries' => 9999]),
            // the child-table sweep is a different axis and is not fitted here
            self::attempt('books/{slug}', 3, ['axis' => 'M', 'queries' => 1]),
        ];
        $routes = [['route' => 'books/{slug}', 'method' => 'GET', 'static' => ['lower_bound' => 'O(N)']]];
        $tasks = [['spec' => ['uri' => 'books/{slug}', 'method' => 'GET', 'static' => ['n_table' => 'pages']]]];

        $fits = CostFit::fromRecords($attempts, $routes, $tasks, 'run-1');

        self::assertSame('0.1', $fits['schema_version']);
        self::assertSame('run-1', $fits['source']);
        self::assertCount(1, $fits['routes']);
        $r = $fits['routes'][0];
        self::assertSame('GET books/{slug}', $r['route']);
        self::assertSame('N', $r['axis']);
        self::assertSame('pages', $r['table']);
        self::assertSame('O(N)', $r['static_lower_bound']);
        self::assertSame(['queries', 'rows_examined', 'peak_memory'], array_keys($r['quantities']));

        $q = $r['quantities'];
        self::assertSame('ok', $q['queries']['fit_status']);
        self::assertEqualsWithDelta(4.0, $q['queries']['a'], 1e-9);
        self::assertEqualsWithDelta(1.0, $q['queries']['b'], 1e-9);
        self::assertEqualsWithDelta(1.0, $q['queries']['k'], 1e-9);
        self::assertSame('ok', $q['rows_examined']['fit_status']);
        self::assertEqualsWithDelta(0.0, $q['rows_examined']['a'], 1e-6);
        self::assertEqualsWithDelta(2.0, $q['rows_examined']['b'], 1e-9);
        self::assertSame('ok', $q['peak_memory']['fit_status']);
        self::assertSame(0.0, $q['peak_memory']['k']);
        self::assertStringNotContainsString('/', $fits['source']);
    }

    public function testARouteThatTimedOutAtTheLargestScaleIsInsufficientForEveryQuantity(): void
    {
        $attempts = [
            ...self::sweep('slow'),
            ...self::sweep('fine'),
        ];
        foreach ($attempts as $i => $a) {
            if ($a['route'] === 'slow' && $a['scale'] === 10000) {
                $attempts[$i] = self::attempt('slow', 10000, ['class' => 'timeout', 'queries' => null, 'rows_examined' => null, 'peak_mem' => null]);
            }
        }

        $fits = CostFit::fromRecords($attempts, [], [], 'run');
        $by = array_column($fits['routes'], null, 'route');

        foreach (['queries', 'rows_examined', 'peak_memory'] as $name) {
            self::assertSame('insufficient_scales', $by['GET slow']['quantities'][$name]['fit_status']);
            self::assertSame('scale 10000: timeout', $by['GET slow']['quantities'][$name]['reason']);
        }
        self::assertSame('ok', $by['GET fine']['quantities']['queries']['fit_status']);
        self::assertNull($by['GET fine']['table']);
    }

    public function testAQuantityNotRecordedAtAScaleIsInsufficientOnlyForThatQuantity(): void
    {
        $attempts = self::sweep('r');
        $attempts[3]['rows_examined'] = null;

        $q = CostFit::fromRecords($attempts, [], [], 'run')['routes'][0]['quantities'];

        self::assertSame('ok', $q['queries']['fit_status']);
        self::assertSame('insufficient_scales', $q['rows_examined']['fit_status']);
        self::assertSame('scale 10000: rows_examined not recorded', $q['rows_examined']['reason']);
    }

    public function testARouteMissingFromAScaleOthersWereMeasuredAtIsInsufficient(): void
    {
        $attempts = [...self::sweep('full'), self::attempt('short', 0), self::attempt('short', 100)];

        $by = array_column(CostFit::fromRecords($attempts, [], [], 'run')['routes'], null, 'route');

        self::assertSame('insufficient_scales', $by['GET short']['quantities']['queries']['fit_status']);
        self::assertSame('scale 1000: not measured; scale 10000: not measured', $by['GET short']['quantities']['queries']['reason']);
    }

    public function testAFlatRouteUnderAStaticLinearBoundIsContradictsStaticForQueriesOnly(): void
    {
        $attempts = self::sweep('flat', ['queries' => 4]);
        $routes = [['route' => 'flat', 'method' => 'GET', 'static' => ['lower_bound' => 'O(N)']]];

        $q = CostFit::fromRecords($attempts, $routes, [], 'run')['routes'][0]['quantities'];

        self::assertSame('contradicts_static', $q['queries']['fit_status']);
        self::assertSame('ok', $q['rows_examined']['fit_status']);
    }

    public function testARouteThatWasNeverRunIsListedWithItsReason(): void
    {
        $routes = [['route' => 'gone', 'method' => 'GET', 'class' => 'unfillable', 'reason_detail' => 'no row to fill {id}', 'static' => []]];

        $r = CostFit::fromRecords(self::sweep('full'), $routes, [], 'run')['routes'];
        $by = array_column($r, null, 'route');

        self::assertSame('insufficient_scales', $by['GET gone']['quantities']['queries']['fit_status']);
        self::assertSame('route not measured: no row to fill {id}', $by['GET gone']['quantities']['queries']['reason']);
    }

    public function testTheCamelCaseFieldNameIsAlsoRead(): void
    {
        $attempts = self::sweep('r');
        foreach ($attempts as $i => $a) {
            $attempts[$i]['rowsExamined'] = $a['rows_examined'];
            unset($attempts[$i]['rows_examined']);
        }

        self::assertSame('ok', CostFit::fromRecords($attempts, [], [], 'run')['routes'][0]['quantities']['rows_examined']['fit_status']);
    }

    // ---------------------------------------------------------------- D105: a route with no empty-system point

    /** @return list<array<string, mixed>> the request at scale 0 answered not_found; 10, 100, 1000, 10000 are fine */
    private static function absentRecordSweep(string $route): array
    {
        $zero = self::attempt($route, 0, ['class' => 'not_found', 'queries' => null, 'rows_examined' => null, 'peak_mem' => null]);

        return [$zero, ...array_map(static fn (int $s): array => self::attempt($route, $s), [10, 100, 1000, 10000])];
    }

    public function testARouteWhoseEmptySystemAnsweredNotFoundIsFittedFromTenWithAFree(): void
    {
        $attempts = [...self::absentRecordSweep('GET /shelves/{slug}'), ...self::sweep('GET /ok')];
        // the other route was measured at 0, 100, 1000, 10000 only; give it 10 as well so every route has the same scales
        $attempts[] = self::attempt('GET /ok', 10);

        $by = array_column(CostFit::fromRecords($attempts, [], [], 'run')['routes'], null, 'route');
        $r = $by['GET GET /shelves/{slug}'];

        self::assertSame('not_found', $r['empty_system']);
        foreach (['queries', 'rows_examined', 'peak_memory'] as $name) {
            self::assertSame('ok', $r['quantities'][$name]['fit_status'], $name);
            self::assertSame([10, 100, 1000, 10000], $r['quantities'][$name]['scales'], $name);
        }
        // y = 4 + N and y = 2 N were what the series were made from
        self::assertEqualsWithDelta(4.0, $r['quantities']['queries']['a'], 1e-9);
        self::assertEqualsWithDelta(1.0, $r['quantities']['queries']['b'], 1e-9);
        self::assertEqualsWithDelta(1.0, $r['quantities']['queries']['k'], 1e-9);
        self::assertEqualsWithDelta(2.0, $r['quantities']['rows_examined']['b'], 1e-9);
        // the pairs never use scale 0, and now there are six of them (four points)
        self::assertCount(6, $r['quantities']['queries']['pairs']);
    }

    public function testTheLastAttemptAtScaleZeroDecidesAndNeedsNoColdAttempt(): void
    {
        // as in a real record: the driver stops at attempt 1 when the empty system answers not_found, after a 422 for a missing body
        $attempts = [
            self::attempt('PUT /sorting', 0, ['attempt' => 1, 'class' => 'validation_422', 'queries' => null, 'rows_examined' => null, 'peak_mem' => null]),
            self::attempt('PUT /sorting', 0, ['attempt' => 1, 'class' => 'not_found', 'queries' => null, 'rows_examined' => null, 'peak_mem' => null]),
            ...array_map(static fn (int $s): array => self::attempt('PUT /sorting', $s), [10, 100, 1000, 10000]),
        ];

        $r = CostFit::fromRecords($attempts, [], [], 'run')['routes'][0];

        self::assertSame('not_found', $r['empty_system']);
        self::assertSame('ok', $r['quantities']['queries']['fit_status']);
        self::assertSame([10, 100, 1000, 10000], $r['quantities']['queries']['scales']);
    }

    public function testAnEmptySystemThatAnsweredA422AndNothingElseIsNotTheException(): void
    {
        $attempts = [
            self::attempt('PUT /sorting', 0, ['attempt' => 1, 'class' => 'validation_422', 'queries' => null, 'rows_examined' => null, 'peak_mem' => null]),
            ...array_map(static fn (int $s): array => self::attempt('PUT /sorting', $s), [10, 100, 1000, 10000]),
        ];

        $r = CostFit::fromRecords($attempts, [], [], 'run')['routes'][0];

        self::assertArrayNotHasKey('empty_system', $r);
        self::assertSame('insufficient_scales', $r['quantities']['queries']['fit_status']);
    }

    public function testTheRoutesWithAnEmptySystemPointAreFittedAsBefore(): void
    {
        $attempts = [...self::absentRecordSweep('absent'), ...self::sweep('GET present'), self::attempt('GET present', 10)];

        $by = array_column(CostFit::fromRecords($attempts, [], [], 'run')['routes'], null, 'route');
        $r = $by['GET GET present'];

        self::assertArrayNotHasKey('empty_system', $r);
        self::assertSame([0, 10, 100, 1000, 10000], $r['quantities']['queries']['scales']);
        // D79: scale 0 pins a for k > 0
        self::assertEqualsWithDelta(4.0, $r['quantities']['queries']['a'], 1e-9);
    }

    public function testOnlyNotFoundAtTheEmptySystemTakesTheException(): void
    {
        $attempts = self::sweep('GET timed');
        $attempts[0] = self::attempt('GET timed', 0, ['class' => 'timeout', 'queries' => null, 'rows_examined' => null, 'peak_mem' => null]);
        $attempts[] = self::attempt('GET timed', 10);

        $r = CostFit::fromRecords($attempts, [], [], 'run')['routes'][0];

        self::assertArrayNotHasKey('empty_system', $r);
        self::assertSame('insufficient_scales', $r['quantities']['queries']['fit_status']);
        self::assertSame('scale 0: timeout', $r['quantities']['queries']['reason']);
    }

    public function testARouteWithoutAnEmptyPointNeedsFourScalesNotThree(): void
    {
        // no small scale: 100, 1000, 10000 would fit three free numbers on three points, exactly, with no residual
        $attempts = [
            self::attempt('GET gone', 0, ['class' => 'not_found', 'queries' => null, 'rows_examined' => null, 'peak_mem' => null]),
            ...array_map(static fn (int $s): array => self::attempt('GET gone', $s), [100, 1000, 10000]),
        ];

        $q = CostFit::fromRecords($attempts, [], [], 'run')['routes'][0]['quantities']['queries'];

        self::assertSame('insufficient_scales', $q['fit_status']);
        self::assertSame('3 scales measured (scale 0 is left out), at least 4 are needed', $q['reason']);
    }

    public function testALargeScaleThatFailedStillMakesNoFormulaForAnAbsentRecordRoute(): void
    {
        $attempts = self::absentRecordSweep('GET slow');
        $attempts[4] = self::attempt('GET slow', 10000, ['class' => 'timeout', 'queries' => null, 'rows_examined' => null, 'peak_mem' => null]);

        $q = CostFit::fromRecords($attempts, [], [], 'run')['routes'][0]['quantities']['queries'];

        self::assertSame('insufficient_scales', $q['fit_status']);
        self::assertSame('scale 10000: timeout', $q['reason']);
    }
}
