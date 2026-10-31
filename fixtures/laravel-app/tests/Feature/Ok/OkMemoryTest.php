<?php

namespace Tests\Feature\Ok;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Peak memory of the /ok routes against fixtures/expected.json. Reference values only (D18):
 * peak usage is noisy, so the verdict is the log-log slope between the two largest scales (GrowthRules, D26).
 * A slope between the O(1) and O(N) limits is inconclusive and fails, so it gets reported and not rounded.
 */
class OkMemoryTest extends OkRouteTestCase
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
    public function test_memory_growth_matches_the_expectation(string $route): void
    {
        $expected = $this->expected($route)['memory'];

        // Boot the app and load the classes once, so the first scale does not pay for them.
        $this->atScale($route, 10, fn () => $this->request($route)->assertOk());

        $peaks = [];
        foreach (self::SCALES as $scale) {
            $peaks[$scale] = $this->atScale($route, $scale, fn () => $this->measurePeakMemory(
                fn () => $this->request($route)->assertOk(),
            ));
        }
        $this->report('peak_bytes', $route, $peaks);

        $this->assertGrowth('memory', $route, $expected, self::SCALES, array_values($peaks));
    }
}
