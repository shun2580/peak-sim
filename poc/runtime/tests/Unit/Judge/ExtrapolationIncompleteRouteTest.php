<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Judge;

use PeakSim\Runtime\Judge\ExtrapolationJudge;
use PeakSim\Runtime\Judge\Predictor;
use PHPUnit\Framework\TestCase;

/**
 * A route whose estimate is not complete (the case of `POST /books/{bookSlug}/copy`, whose fits have the status
 * `insufficient_scales` for every quantity because the scale 10,000 was never measured) must not enter the comparison of
 * the extrapolation test: not a denominator, not the routes "with a formula" that the 20% line is taken of. The check goes
 * the way the tools go: fits -> `Predictor::predict` (what `bin/predict` prints) -> `ExtrapolationJudge::judge` (what
 * `bin/compare-extrapolation` runs). Nothing here reads an estimate; the inputs are made up and every number is written by hand.
 */
final class ExtrapolationIncompleteRouteTest extends TestCase
{
    private const AT = 300000;

    private const COPY = 'POST /books/{bookSlug}/copy';

    /** @return array<string, mixed> a fit that predicts exactly 100 at any size (b = 0) */
    private static function okFit(): array
    {
        return ['fit_status' => 'ok', 'a' => 100.0, 'b' => 0.0, 'k' => 1.0];
    }

    /** @return array<string, mixed> the three quantities of the copy route as its real fits record them */
    private static function insufficientRoute(): array
    {
        $fit = ['fit_status' => 'insufficient_scales', 'reason' => 'scale 10000: not measured'];

        return ['route' => self::COPY, 'axis' => 'N', 'table' => 'entities', 'static_lower_bound' => 'O(N)', 'empty_system' => 'not_found',
            'quantities' => ['queries' => $fit, 'rows_examined' => $fit, 'peak_memory' => $fit]];
    }

    /** @return array<string, mixed> */
    private static function okRoute(string $name): array
    {
        return ['route' => $name, 'axis' => 'N', 'table' => 'entities', 'static_lower_bound' => 'O(N)',
            'quantities' => ['queries' => self::okFit(), 'rows_examined' => self::okFit(), 'peak_memory' => self::okFit()]];
    }

    /** @return array<string, mixed> a cold try at the predicted size */
    private static function cold(string $method, string $route, string $class, ?int $queries, ?int $rows, ?int $mem): array
    {
        return ['route' => $route, 'method' => $method, 'scale' => self::AT, 'axis' => 'N', 'attempt' => 2, 'class' => $class,
            'queries' => $queries, 'rows_examined' => $rows, 'peak_mem' => $mem];
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>} predictions of 5 ok routes and the copy route,
     *                                                                       and the cold tries of the 5 ok routes (all inside)
     */
    private static function fixture(): array
    {
        $routes = [self::insufficientRoute()];
        $attempts = [];
        for ($i = 0; $i < 5; ++$i) {
            $routes[] = self::okRoute(sprintf('GET /r%d', $i));
            $attempts[] = self::cold('GET', sprintf('/r%d', $i), 'ok', 100, 100, 100);
        }

        return [Predictor::predict(['source' => 'made-up', 'routes' => $routes], self::AT), $attempts];
    }

    public function test_the_predictor_gives_the_incomplete_route_no_value_for_any_quantity(): void
    {
        [$p] = self::fixture();

        $byName = array_column($p['routes'], null, 'route');
        foreach (Predictor::QUANTITIES as $q) {
            $this->assertSame('not_predicted', $byName[self::COPY]['quantities'][$q]['status']);
            $this->assertSame('insufficient_scales', $byName[self::COPY]['quantities'][$q]['fit_status']);
            $this->assertArrayNotHasKey('value', $byName[self::COPY]['quantities'][$q]);
        }
    }

    /**
     * @return array<string, array{0: string, 1: int|null, 2: int|null, 3: int|null}> what the cold try of the copy route at the
     *                                                                                predicted size may look like
     */
    public static function copyOutcomes(): array
    {
        return [
            'ran past the 600 seconds (exception, nothing collected)' => ['exception', null, null, null],
            'timeout' => ['timeout', null, null, null],
            'answered ok with zeros (the 0.0 of its estimate row, read as a point)' => ['ok', 0, 0, 0],
            'answered ok with real values far from zero' => ['ok', 5000, 900000, 80000000],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('copyOutcomes')]
    public function test_whatever_the_incomplete_route_does_at_the_predicted_size_it_is_in_no_denominator_and_not_unmeasured(string $class, ?int $queries, ?int $rows, ?int $mem): void
    {
        [$p, $a] = self::fixture();
        $a[] = self::cold('POST', '/books/{bookSlug}/copy', $class, $queries, $rows, $mem);

        $r = ExtrapolationJudge::judge($p, $a);

        $this->assertSame(6, $r['summary']['routes']);
        foreach (Predictor::QUANTITIES as $q) {
            $c = $r['quantities'][$q];
            $this->assertSame(5, $c['denominator'], $q);
            $this->assertSame(5, $c['within'], $q);
            $this->assertSame(0, $c['unmeasured'], $q);
            $this->assertSame(0, $c['contradicts_static'], $q);
            $this->assertSame(1, $c['insufficient_scales'], $q);
            $this->assertSame(5, $c['with_formula'], $q);
        }
        $byName = array_column($r['routes'], null, 'route');
        foreach (Predictor::QUANTITIES as $q) {
            $this->assertSame('insufficient_scales', $byName[self::COPY]['quantities'][$q]['category']);
            $this->assertSame('no_prediction', $byName[self::COPY]['quantities'][$q]['outcome']);
        }
        $this->assertSame('pass', $r['verdict']);
        $this->assertStringContainsString('| POST /books/{bookSlug}/copy | queries | insufficient_scales | scale 10000: not measured |', ExtrapolationJudge::toMarkdown($r));
    }

    public function test_the_verdict_and_every_count_are_the_same_with_the_incomplete_route_and_without_it(): void
    {
        [$withCopy, $a] = self::fixture();
        $without = $withCopy;
        $without['routes'] = array_values(array_filter($without['routes'], static fn (array $x): bool => $x['route'] !== self::COPY));

        $with = ExtrapolationJudge::judge($withCopy, $a);
        $off = ExtrapolationJudge::judge($without, $a);

        $this->assertSame($off['verdict'], $with['verdict']);
        foreach (Predictor::QUANTITIES as $q) {
            foreach (['denominator', 'within', 'unmeasured', 'contradicts_static', 'with_formula', 'percent'] as $key) {
                $this->assertSame($off['quantities'][$q][$key], $with['quantities'][$q][$key], "$q $key");
            }
        }
    }

    public function test_a_route_with_an_estimate_not_measured_is_a_different_thing_and_still_counts_as_unmeasured(): void
    {
        // The control: an ok formula whose cold try failed IS counted (unmeasured, in the base of the 20% line); only the
        // routes with no formula are left out. So the first test is not passing because the judge ignores everything.
        [$p, $a] = self::fixture();
        $p['routes'][] = Predictor::predict(['routes' => [self::okRoute('GET /slow')]], self::AT)['routes'][0];
        $a[] = self::cold('GET', '/slow', 'timeout', null, null, null);

        $r = ExtrapolationJudge::judge($p, $a);

        $this->assertSame(1, $r['quantities']['queries']['unmeasured']);
        $this->assertSame(6, $r['quantities']['queries']['with_formula']);
        $this->assertSame(1, $r['quantities']['queries']['insufficient_scales']);
    }
}
