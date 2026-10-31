<?php

declare(strict_types=1);

namespace PeakSim\Tests\Prototype;

use PeakSim\Prototype\Analyzer;
use PeakSim\Prototype\RouteAnalysis;
use PHPUnit\Framework\TestCase;

/**
 * Checks the T3 prototype against fixtures/expected.json for the three routes in scope.
 *
 * The expected values are read from expected.json at run time and are not copied here.
 * Behaviour for routes outside the three targets is not specified and is not tested.
 */
final class ExpectedRoutesTest extends TestCase
{
    /** Routes the prototype is asked to judge (names only; values come from expected.json). */
    private const TARGET_ROUTES = ['/ng/n-plus-one', '/ok/eager', '/ng/layered'];

    private const CONFIDENCE_LEVELS = ['high', 'medium', 'low'];

    private const COMPLEXITY_LABELS = ['O(1)', 'O(N)', 'O(N*M)', 'unknown'];

    private static function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function appRoot(): string
    {
        return self::projectRoot() . '/fixtures/laravel-app';
    }

    private static function exportDir(): string
    {
        return self::projectRoot() . '/fixtures/artisan-export';
    }

    private static function analyzer(): Analyzer
    {
        return new Analyzer(self::appRoot(), self::exportDir());
    }

    /**
     * One expected.json entry per target route, keyed by route.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function expectedByRoute(): array
    {
        $path = self::projectRoot() . '/fixtures/expected.json';
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException('cannot read fixtures/expected.json');
        }
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $byRoute = [];
        foreach ($data['routes'] as $entry) {
            if (in_array($entry['route'], self::TARGET_ROUTES, true)) {
                $byRoute[$entry['route']] = $entry;
            }
        }
        foreach (self::TARGET_ROUTES as $route) {
            if (!isset($byRoute[$route])) {
                throw new \RuntimeException("fixtures/expected.json has no entry for {$route}");
            }
        }

        return $byRoute;
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function expectedRouteProvider(): array
    {
        $cases = [];
        foreach (self::expectedByRoute() as $route => $entry) {
            $cases[$route] = [$entry];
        }

        return $cases;
    }

    /** @return array<string, array{0: string}> */
    public static function targetRouteProvider(): array
    {
        $cases = [];
        foreach (self::TARGET_ROUTES as $route) {
            $cases[$route] = [$route];
        }

        return $cases;
    }

    /** @param array<string, mixed> $expected */
    #[\PHPUnit\Framework\Attributes\DataProvider('expectedRouteProvider')]
    public function testQueryComplexityMatchesExpectedJson(array $expected): void
    {
        $actual = self::analyzer()->analyzeRoute($expected['route']);

        $this->assertSame(
            $expected['query'],
            $actual->query,
            "{$expected['route']}: query complexity should equal expected.json query"
        );
    }

    /** @param array<string, mixed> $expected */
    #[\PHPUnit\Framework\Attributes\DataProvider('expectedRouteProvider')]
    public function testMemoryComplexityMatchesExpectedJson(array $expected): void
    {
        $actual = self::analyzer()->analyzeRoute($expected['route']);

        $this->assertSame(
            $expected['memory'],
            $actual->memory,
            "{$expected['route']}: memory complexity should equal expected.json memory"
        );
    }

    /** @param array<string, mixed> $expected */
    #[\PHPUnit\Framework\Attributes\DataProvider('expectedRouteProvider')]
    public function testGrowthTableMatchesExpectedJson(array $expected): void
    {
        $actual = self::analyzer()->analyzeRoute($expected['route']);

        $this->assertSame(
            $expected['n_table'],
            $actual->nTable,
            "{$expected['route']}: N table should equal expected.json n_table"
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('targetRouteProvider')]
    public function testResultUsesDocumentedVocabulary(string $route): void
    {
        $actual = self::analyzer()->analyzeRoute($route);

        $this->assertContains(
            $actual->query,
            self::COMPLEXITY_LABELS,
            "{$route}: query must be one of O(1) / O(N) / O(N*M) / unknown"
        );
        $this->assertContains(
            $actual->memory,
            self::COMPLEXITY_LABELS,
            "{$route}: memory must be one of O(1) / O(N) / O(N*M) / unknown"
        );
        $this->assertContains(
            $actual->confidence,
            self::CONFIDENCE_LEVELS,
            "{$route}: confidence must be one of high / medium / low"
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('targetRouteProvider')]
    public function testUriIsTheStringThatWasPassedIn(string $route): void
    {
        $actual = self::analyzer()->analyzeRoute($route);

        $this->assertSame(
            $route,
            $actual->uri,
            "{$route}: uri must be the input string as given (same form as expected.json route), not a normalised one"
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('targetRouteProvider')]
    public function testEvidenceIsNotEmpty(string $route): void
    {
        $actual = self::analyzer()->analyzeRoute($route);

        $this->assertNotEmpty(
            $actual->evidence,
            "{$route}: evidence must contain at least one entry"
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('targetRouteProvider')]
    public function testEvidencePointsToRealFilesAndLines(string $route): void
    {
        $actual = self::analyzer()->analyzeRoute($route);
        $appRoot = self::appRoot();

        foreach ($actual->evidence as $i => $item) {
            $where = "{$route}: evidence[{$i}]";

            $this->assertArrayHasKey('file', $item, "{$where} must have a file");
            $this->assertArrayHasKey('line', $item, "{$where} must have a line");
            $this->assertArrayHasKey('note', $item, "{$where} must have a note");

            $this->assertIsString($item['file'], "{$where}.file must be a string");
            $this->assertIsInt($item['line'], "{$where}.line must be an int");
            $this->assertIsString($item['note'], "{$where}.note must be a string");

            $this->assertStringStartsNotWith(
                '/',
                $item['file'],
                "{$where}.file must be relative to the app root, not absolute"
            );
            $this->assertFileExists(
                $appRoot . '/' . $item['file'],
                "{$where}.file must exist under the app root"
            );
            $this->assertGreaterThanOrEqual(
                1,
                $item['line'],
                "{$where}.line must be 1 or greater"
            );
            $this->assertLessThanOrEqual(
                count(file($appRoot . '/' . $item['file'])),
                $item['line'],
                "{$where}.line must not be beyond the last line of {$item['file']}"
            );
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('targetRouteProvider')]
    public function testAnalysisIsDeterministic(string $route): void
    {
        $analyzer = self::analyzer();

        $first = $analyzer->analyzeRoute($route);
        $second = $analyzer->analyzeRoute($route);
        $fresh = self::analyzer()->analyzeRoute($route);

        $this->assertSame(
            self::fingerprint($first),
            self::fingerprint($second),
            "{$route}: analysing twice with the same analyzer must give identical results"
        );
        $this->assertSame(
            self::fingerprint($first),
            self::fingerprint($fresh),
            "{$route}: analysing with a fresh analyzer must give identical results"
        );
    }

    public function testLayeredRouteShowsBindingResolutionAndInnermostQuery(): void
    {
        $actual = self::analyzer()->analyzeRoute('/ng/layered');

        $bindSites = self::evidenceIn($actual, 'app/Ng/Providers/LayeredServiceProvider.php');
        $this->assertNotEmpty(
            $bindSites,
            '/ng/layered: evidence must name the ServiceProvider that binds the repository interface (LayeredServiceProvider.php)'
        );
        $this->assertNotEmpty(
            self::linesContaining($bindSites, 'bind('),
            '/ng/layered: the evidence line in LayeredServiceProvider.php must be the bind( call that resolves the interface'
        );

        $querySites = self::evidenceIn($actual, 'app/Ng/Repositories/EloquentOrderRepository.php');
        $this->assertNotEmpty(
            $querySites,
            '/ng/layered: evidence must name the implementation that issues the innermost query (EloquentOrderRepository.php)'
        );
        $this->assertNotEmpty(
            self::linesContaining($querySites, 'customer'),
            "/ng/layered: the evidence line in EloquentOrderRepository.php must be the lazy 'customer' read, not an unrelated line"
        );
    }

    public function testEagerLoadingRouteIsNotJudgedAsNPlusOne(): void
    {
        $actual = self::analyzer()->analyzeRoute('/ok/eager');

        $this->assertSame(
            'O(1)',
            $actual->query,
            '/ok/eager: with() eager loading must cancel the N+1, so query stays O(1)'
        );
    }

    public function testLazyRelationInLoopRouteIsJudgedAsNPlusOne(): void
    {
        $actual = self::analyzer()->analyzeRoute('/ng/n-plus-one');

        $this->assertSame(
            'O(N)',
            $actual->query,
            '/ng/n-plus-one: a relation read inside a loop must be judged O(N) in queries'
        );
    }

    private static function fingerprint(RouteAnalysis $analysis): string
    {
        return serialize($analysis);
    }

    /**
     * Evidence entries whose file is exactly $file (relative to the app root).
     *
     * @return list<array{file: string, line: int, note: string}>
     */
    private static function evidenceIn(RouteAnalysis $analysis, string $file): array
    {
        return array_values(array_filter(
            $analysis->evidence,
            static fn (array $item): bool => $item['file'] === $file
        ));
    }

    /**
     * Entries whose cited line, read from the file itself, contains $needle.
     *
     * @param list<array{file: string, line: int, note: string}> $items
     * @return list<array{file: string, line: int, note: string}>
     */
    private static function linesContaining(array $items, string $needle): array
    {
        return array_values(array_filter($items, static function (array $item) use ($needle): bool {
            $lines = file(self::appRoot() . '/' . $item['file']);

            return $lines !== false
                && isset($lines[$item['line'] - 1])
                && str_contains($lines[$item['line'] - 1], $needle);
        }));
    }
}
