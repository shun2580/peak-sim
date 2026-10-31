<?php

namespace Tests\Feature\Ok;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Peak memory of the /ok routes against fixtures/expected.json. Reference values only (D18):
 * peak usage is noisy, so the test compares growth between the smallest and largest scale.
 *
 * Fixed before measuring: growth ratio = peak(10000 rows) / peak(100 rows).
 *   O(1) expected -> ratio must be at most 2;  O(N) expected -> ratio must be at least 10 (linear would be about 100).
 * A ratio in between fits neither and fails.
 */
class OkMemoryTest extends OkRouteTestCase
{
    private const CONSTANT_AT_MOST = 2.0;

    private const LINEAR_AT_LEAST = 10.0;

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
        $this->atScale($route, 10, fn () => $this->get($route)->assertOk());

        $peaks = [];
        foreach (self::SCALES as $scale) {
            $peaks[$scale] = $this->atScale($route, $scale, fn () => $this->measurePeakMemory(
                fn () => $this->get($route)->assertOk(),
            ));
        }
        $this->report('peak_bytes', $route, $peaks);

        $ratio = $peaks[self::SCALES[array_key_last(self::SCALES)]] / max(1, $peaks[self::SCALES[0]]);

        match ($expected) {
            'O(1)' => $this->assertLessThanOrEqual(self::CONSTANT_AT_MOST, $ratio, "{$route}: expected O(1), growth ratio {$ratio}"),
            'O(N)' => $this->assertGreaterThanOrEqual(self::LINEAR_AT_LEAST, $ratio, "{$route}: expected O(N), growth ratio {$ratio}"),
        };
    }
}
