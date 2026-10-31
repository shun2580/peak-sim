<?php

declare(strict_types=1);

namespace PeakSim\Tests\Acceptance;

use PeakSim\Tests\Support\ExpectationFixtureRows;
use PeakSim\Tests\Support\MiniAppAnalyzer;
use PeakSim\Tests\Support\MiniAppRealAnalyzer;
use PeakSim\Tests\Support\MiniAppWorkspace;
use PeakSim\Tests\Support\ReportAssert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * No miss, on its own (design 3.2-1): whatever else is wrong, the tool must never report a route lighter than
 * expected, axis by axis. "unknown" counts as the heaviest answer, so "could not follow" never fails this test
 * and "looks safe" always can. An equality test that fails still lets this one say whether the failure was a miss.
 */
final class MissGuardTest extends TestCase
{
    protected function analyzer(): MiniAppAnalyzer
    {
        return new MiniAppRealAnalyzer();
    }

    /** @return array<string, array{0: string, 1: string}> route + axis; the cache route also guards both of its sides */
    public static function axisProvider(): array
    {
        $cases = [];
        foreach (ExpectationFixtureRows::rows() as $uri => $row) {
            foreach (['query', 'memory'] as $axis) {
                if ($row[$axis] !== null || ($axis === 'query' && $row['query_on_miss'] !== null)) {
                    $cases["{$uri} {$axis}"] = [$uri, $axis];
                }
            }
            if ($row['cached'] === true) {
                $cases["{$uri} cache on_miss"] = [$uri, 'cache_on_miss'];
                $cases["{$uri} cache on_hit"] = [$uri, 'cache_on_hit'];
            }
        }

        return $cases;
    }

    #[DataProvider('axisProvider')]
    public function testTheRouteIsNeverLighterThanExpected(string $uri, string $axis): void
    {
        $row = ExpectationFixtureRows::rows()[$uri];
        $view = $this->analyzer()->analyzeRoute(MiniAppWorkspace::fixtureApp(), MiniAppWorkspace::fixtureExport(), $uri);

        [$expected, $actual] = match ($axis) {
            'query' => [$row['query'] ?? $row['query_on_miss'], $view['query']['growth']],
            'memory' => [$row['memory'], $view['memory']['growth']],
            'cache_on_miss' => [$row['query_on_miss'], $view['cache']['on_miss']['query']['growth'] ?? 'O(1)'],
            'cache_on_hit' => [$row['query_on_hit'], $view['cache']['on_hit']['query']['growth'] ?? 'O(1)'],
        };

        $this->assertGreaterThanOrEqual(
            ReportAssert::rank($expected),
            ReportAssert::rank($actual),
            "{$uri} {$axis}: expected {$expected}, the tool said {$actual} — a MISS (a lighter verdict than the truth)"
        );
    }
}
