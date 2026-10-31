<?php

namespace Tests\Feature\Ok;

use App\Http\Controllers\Ok\OrderController;
use PHPUnit\Framework\Attributes\DataProvider;
use Ralder\QueryScaling\Assertions\QueryScalingAssertions;
use Ralder\QueryScaling\QueryGrowth;

/**
 * Query-count growth of the /ok routes against fixtures/expected.json.
 * The verdict comes from ralder/phpunit-query-scaling; a plain DB::listen counter reports the numbers.
 * A mismatch stays red: expectations are never loosened to fit a measurement (D18).
 */
class OkQueryScalingTest extends OkRouteTestCase
{
    use QueryScalingAssertions;

    /** @return array<string, array{string}> */
    public static function routes(): array
    {
        return [
            '/ok/eager' => ['/ok/eager'],
            '/ok/paginate' => ['/ok/paginate'],
            '/ok/chunk' => ['/ok/chunk'],
            '/ok/lazy' => ['/ok/lazy'],
            '/ok/with-count' => ['/ok/with-count'],
            '/ok/bulk' => ['/ok/bulk'],
        ];
    }

    #[DataProvider('routes')]
    public function test_query_growth_matches_the_expectation(string $route): void
    {
        $expected = $this->expected($route);

        $counts = [];
        foreach (self::SCALES as $scale) {
            $counts[$scale] = $this->atScale($route, $scale, fn () => $this->countQueries(
                fn () => $this->get($route)->assertOk(),
            ));
        }
        $this->report('queries', $route, $counts);

        $populate = fn (int $scale) => $this->seedFor($route, $scale);
        $run = fn () => $this->get($route)->assertOk();

        if ($expected['query'] === 'O(1)') {
            $this->assertQueriesScaleConstantly(scales: self::SCALES, populate: $populate, run: $run);

            return;
        }

        // O(N) with a coefficient of 1/chunk_size: no faster than one query per chunk, and really growing.
        $this->assertSame('O(N)', $expected['query']);
        $this->assertSame('1/chunk_size', $expected['query_coefficient']);

        $this->assertQueryScaling(
            scales: self::SCALES,
            expectation: QueryGrowth::atMostSlope(1 / OrderController::CHUNK_SIZE),
            populate: $populate,
            run: $run,
        );

        $first = self::SCALES[0];
        $last = self::SCALES[array_key_last(self::SCALES)];
        $perRow = ($counts[$last] - $counts[$first]) / ($last - $first);
        $this->assertEqualsWithDelta(
            1 / OrderController::CHUNK_SIZE,
            $perRow,
            0.1 / OrderController::CHUNK_SIZE,
            "{$route} should issue about one query per chunk of ".OrderController::CHUNK_SIZE.' rows',
        );
    }
}
