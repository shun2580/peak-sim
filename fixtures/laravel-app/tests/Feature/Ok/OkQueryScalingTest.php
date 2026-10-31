<?php

namespace Tests\Feature\Ok;

use App\Http\Controllers\Ok\OrderController;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Query-count growth of the /ok routes against fixtures/expected.json.
 * phpunit-query-scaling measures; the verdict comes from GrowthRules (the one rule for every group, D26).
 * A mismatch stays red: expectations are never loosened to fit a measurement (D18).
 */
class OkQueryScalingTest extends OkRouteTestCase
{
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

        $counts = $this->measureQueryCounts(
            scales: self::SCALES,
            populate: fn (int $scale) => $this->seedFor($route, $scale),
            run: fn () => $this->request($route)->assertOk(),
        );
        $this->assertGrowth('queries', $route, $expected['query'], self::SCALES, $counts);

        if ($expected['query'] === 'O(1)') {
            return;
        }

        // O(N) with a coefficient of 1/chunk_size: about one query per chunk of rows.
        $this->assertSame('1/chunk_size', $expected['query_coefficient']);

        $first = self::SCALES[0];
        $last = self::SCALES[array_key_last(self::SCALES)];
        $perRow = ($counts[array_key_last($counts)] - $counts[0]) / ($last - $first);
        $this->assertEqualsWithDelta(
            1 / OrderController::CHUNK_SIZE,
            $perRow,
            0.1 / OrderController::CHUNK_SIZE,
            "{$route} should issue about one query per chunk of ".OrderController::CHUNK_SIZE.' rows',
        );
    }
}
