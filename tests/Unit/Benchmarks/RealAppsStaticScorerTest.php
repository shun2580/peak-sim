<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Benchmarks;

use PeakSim\Benchmarks\RealApps\StaticScorer;
use PeakSim\Benchmarks\RealApps\Truth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/benchmarks/real-apps/lib/Truth.php';
require_once dirname(__DIR__, 3) . '/benchmarks/real-apps/lib/StaticScorer.php';

/**
 * The scoring of the static analysis against the fixed answers: every category, with the smallest input that makes it.
 */
final class RealAppsStaticScorerTest extends TestCase
{
    /** @return array<string, array{array<string, mixed>, ?string, ?string, string}> */
    public static function axisCases(): array
    {
        $fixed = static fn (string $g): array => ['growth' => $g, 'confidence' => 'high'];
        $cond = ['growth' => 'conditional', 'growth_if_condition_degree' => 1, 'confidence' => 'medium'];
        $floor = ['growth' => 'O(N)', 'at_least' => true, 'confidence' => 'medium'];

        return [
            'determined and equal' => [$fixed('O(N)'), 'O(N)', null, 'match'],
            'determined O(1) and equal' => [$fixed('O(1)'), 'O(1)', null, 'match'],
            'said light, actually heavy' => [$fixed('O(N)'), 'O(1)', null, 'missed'],
            'said light, actually nested' => [$fixed('O(N*M)'), 'O(N)', null, 'missed'],
            'said heavy, actually light' => [$fixed('O(1)'), 'O(N)', null, 'overclaim'],
            'unknown, bound equal' => [$fixed('O(N)'), 'unknown', 'O(N)', 'bound_tight'],
            'unknown, bound below' => [$fixed('O(N*M)'), 'unknown', 'O(N)', 'bound_loose'],
            'unknown, bound above' => [$fixed('O(N)'), 'unknown', 'O(N*M)', 'bound_over'],
            'unknown, bound above O(1)' => [$fixed('O(1)'), 'unknown', 'O(N)', 'bound_over'],
            'unknown, no bound' => [$fixed('O(N)'), 'unknown', null, 'unknown'],
            'a bound of O(1) claims nothing' => [$fixed('O(N)'), 'unknown', 'O(1)', 'unknown'],
            'conditional, determined at the heavy side' => [$cond, 'O(N)', null, 'conditional_ok'],
            'conditional, determined light' => [$cond, 'O(1)', null, 'missed_if_condition'],
            'conditional, determined above the heavy side' => [$cond, 'O(N*M)', null, 'overclaim'],
            'conditional, bound rests on the condition' => [$cond, 'unknown', 'O(N)', 'bound_on_conditional'],
            'conditional, bound above the heavy side' => [$cond, 'unknown', 'O(N*M)', 'bound_over'],
            'conditional, no bound' => [$cond, 'unknown', null, 'unknown'],
            'at least, determined below the floor' => [$floor, 'O(1)', null, 'missed'],
            'at least, determined on the floor' => [$floor, 'O(N)', null, 'match'],
            'at least, determined above the floor' => [$floor, 'O(N*M)', null, 'unverifiable'],
            'at least, bound above the floor' => [$floor, 'unknown', 'O(N*M)', 'unverifiable'],
            'at least, bound on the floor' => [$floor, 'unknown', 'O(N)', 'bound_tight'],
            'answer not decided (null)' => [['growth' => null], 'O(N)', null, 'truth_undecided'],
            'answer not decided (flag)' => [['growth' => 'O(N)', 'decided' => false], 'O(N)', null, 'truth_undecided'],
        ];
    }

    /**
     * @param array<string, mixed> $truthAxis
     */
    #[DataProvider('axisCases')]
    public function testOneAxisFallsIntoTheRightCategory(array $truthAxis, ?string $growth, ?string $bound, string $expected): void
    {
        $this->assertSame($expected, StaticScorer::classify($truthAxis, $growth, $bound));
    }

    public function testEveryCategoryTheClassifierCanReturnIsListed(): void
    {
        $seen = [];
        foreach (self::axisCases() as [$axis, $growth, $bound, $expected]) {
            $seen[$expected] = true;
        }
        foreach (array_keys($seen) as $category) {
            $this->assertContains($category, StaticScorer::CATEGORIES);
        }
    }

    public function testRoutesAreFoundByMethodAndUriAndScoredPerAxis(): void
    {
        $truth = $this->truth([
            $this->route('a GET /x', 'a', 'GET', '/x', ['query' => ['growth' => 'O(N)', 'confidence' => 'high'], 'memory' => ['growth' => 'O(1)', 'confidence' => 'high']], ['round1-target']),
            $this->route('a POST /gone', 'a', 'POST', '/gone', ['query' => ['growth' => 'O(N)', 'confidence' => 'high']], ['round1-target']),
            $this->route('a GET /twice', 'a', 'GET', '/twice', ['query' => ['growth' => 'O(N)', 'confidence' => 'high']], ['settled']),
            $this->route('b GET /y', 'b', 'GET', '/y', ['query' => ['growth' => 'O(1)', 'confidence' => 'high']], ['settled']),
        ]);
        $analyses = [
            'a' => ['routes' => [
                ['route' => '/x', 'methods' => ['GET', 'HEAD'], 'query' => ['growth' => 'unknown', 'lower_bound' => 'O(N)'], 'memory' => ['growth' => 'O(1)', 'lower_bound' => null]],
                ['route' => '/twice', 'methods' => ['GET'], 'query' => ['growth' => 'O(N)'], 'memory' => []],
                ['route' => '/twice', 'methods' => ['GET'], 'query' => ['growth' => 'O(N)'], 'memory' => []],
            ]],
        ];

        $result = StaticScorer::score($truth, $analyses);

        $byKey = [];
        foreach ($result['rows'] as $row) {
            $byKey[$row['id'] . ' ' . $row['axis']] = $row['category'];
        }
        $this->assertSame([
            'a GET /x query' => 'bound_tight',
            'a GET /x memory' => 'match',
            'a POST /gone query' => 'route_missing',
            'a GET /twice query' => 'route_ambiguous',
        ], $byKey, 'app b has no analysis in this run, so its answers are not part of it');

        $all = $result['summary']['all'];
        $this->assertSame(4, $all['answers']);
        $this->assertSame(2, $all['scored']);
        $this->assertSame(2, $all['agree']);
        $this->assertSame(0, $all['missed']);
    }

    public function testFiltersKeepOnlyTheRequestedAppsSetsAndAxes(): void
    {
        $truth = $this->truth([
            $this->route('a GET /x', 'a', 'GET', '/x', ['query' => ['growth' => 'O(1)', 'confidence' => 'high'], 'memory' => ['growth' => 'O(1)', 'confidence' => 'high']], ['settled']),
            $this->route('a GET /y', 'a', 'GET', '/y', ['query' => ['growth' => 'O(N)', 'confidence' => 'high']], ['round1-target']),
        ]);
        $analyses = ['a' => ['routes' => [
            ['route' => '/x', 'methods' => ['GET'], 'query' => ['growth' => 'O(1)'], 'memory' => ['growth' => 'O(N)']],
            ['route' => '/y', 'methods' => ['GET'], 'query' => ['growth' => 'O(1)'], 'memory' => []],
        ]]];

        $bySet = StaticScorer::score($truth, $analyses, ['sets' => ['settled']]);
        $this->assertCount(2, $bySet['rows']);

        $byAxis = StaticScorer::score($truth, $analyses, ['axes' => ['query']]);
        $this->assertSame(['match', 'missed'], array_column($byAxis['rows'], 'category'));

        $byApp = StaticScorer::score($truth, $analyses, ['apps' => ['other']]);
        $this->assertSame([], $byApp['rows']);
    }

    public function testASetCountsTheRoutesThatCarryItEvenWhenTheyCarryManySets(): void
    {
        $truth = $this->truth([
            $this->route('a GET /x', 'a', 'GET', '/x', ['query' => ['growth' => 'O(1)', 'confidence' => 'high']], ['settled', 'round1-control']),
        ]);
        $analyses = ['a' => ['routes' => [['route' => '/x', 'methods' => ['GET'], 'query' => ['growth' => 'O(1)'], 'memory' => []]]]];

        $summary = StaticScorer::score($truth, $analyses)['summary'];

        $this->assertSame(1, $summary['set:settled']['answers']);
        $this->assertSame(1, $summary['set:round1-control']['answers']);
        $this->assertSame(1, $summary['app:a']['answers']);
        $this->assertSame(1, $summary['axis:query']['answers']);
    }

    public function testTheCommittedAnswersLoadAndAreWellFormed(): void
    {
        $truth = Truth::load(dirname(__DIR__, 3) . '/benchmarks/real-apps/truth.json');

        $this->assertNotEmpty($truth['routes']);
        foreach ($truth['apps'] as $name => $app) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $app['commit'], $name);
        }
        foreach ($truth['routes'] as $route) {
            $this->assertNotEmpty($route['evidence'], $route['id'] . ' needs at least one file:line');
            foreach ($route['evidence'] as $e) {
                $this->assertIsInt($e['line'] ?? null, $route['id']);
                $this->assertStringNotContainsString('..', $e['file']);
                $this->assertStringStartsNotWith('/', $e['file'], 'a path inside the app, not an absolute path');
            }
            $this->assertSame(1, preg_match('/^[a-z-]+ (GET|POST|PUT|PATCH|DELETE) \//', $route['id']), $route['id']);
            $this->assertSame($route['app'] . ' ' . $route['method'] . ' ' . $route['uri'], $route['id']);
        }
    }

    public function testTheCommittedCollectionsHaveTheRecordedSizes(): void
    {
        $truth = Truth::load(dirname(__DIR__, 3) . '/benchmarks/real-apps/truth.json');

        $perSet = [];
        $perApp = [];
        $axes = 0;
        foreach ($truth['routes'] as $route) {
            foreach ($route['sets'] as $set) {
                $perSet[$set] = ($perSet[$set] ?? 0) + 1;
            }
            $perApp[$route['app']] = ($perApp[$route['app']] ?? 0) + 1;
            $axes += count($route['axes']);
        }
        ksort($perSet);
        ksort($perApp);

        $this->assertCount(111, $truth['routes']);
        $this->assertSame(154, $axes);
        $this->assertSame([
            'lower-bound-sample' => 15,
            'round1-control' => 2,
            'round1-target' => 25,
            'round2-new24' => 24,
            'round3-bookstack' => 42,
            'settled' => 5,
        ], $perSet);
        $this->assertSame(['bookstack' => 47, 'firefly-iii' => 53, 'koel' => 7, 'snipe-it' => 4], $perApp);
    }

    public function testTheNewTwentyFourAreAnsweredInEnglishWithTheirConditionsAndEvidence(): void
    {
        $truth = Truth::load(dirname(__DIR__, 3) . '/benchmarks/real-apps/truth.json');

        $new = array_values(array_filter($truth['routes'], static fn (array $r): bool => in_array('round2-new24', $r['sets'], true)));
        $this->assertCount(24, $new);

        $growth = [];
        foreach ($new as $route) {
            $this->assertSame('firefly-iii', $route['app']);
            $this->assertArrayHasKey('query', $route['axes'], $route['id']);
            $q = $route['axes']['query'];
            $growth[$q['growth']] = ($growth[$q['growth']] ?? 0) + 1;
            $this->assertContains($q['confidence'], ['high', 'medium'], $route['id']);
            $this->assertNotEmpty($route['evidence'], $route['id']);
            if ($q['growth'] === 'conditional') {
                $this->assertNotSame('', trim((string) ($q['condition'] ?? '')), $route['id'] . ' needs the condition in words');
                $this->assertSame(1, $q['growth_if_condition_degree'], $route['id']);
            }
            // the prose is English: no character from the Japanese blocks
            $this->assertSame(0, preg_match('/[\x{3000}-\x{30ff}\x{3400}-\x{9fff}\x{ff00}-\x{ffef}]/u', (string) json_encode($route, JSON_UNESCAPED_UNICODE)), $route['id']);
        }
        ksort($growth);
        $this->assertSame(['O(1)' => 2, 'O(N)' => 16, 'conditional' => 6], $growth);
    }

    public function testTheRouteThatOverlapsTheEarlierSampleKeepsItsEarlierRecordAsInformation(): void
    {
        $truth = Truth::load(dirname(__DIR__, 3) . '/benchmarks/real-apps/truth.json');

        $found = array_values(array_filter($truth['routes'], static fn (array $r): bool => $r['id'] === 'firefly-iii POST /piggy-banks/remove/{piggyBank}'));
        $this->assertCount(1, $found);
        $this->assertSame(['round2-new24'], $found[0]['sets'], 'one answer, in one set');
        $this->assertSame('conditional', $found[0]['axes']['query']['growth'], 'the full trace is the answer');
        $this->assertSame(['S10'], array_values(array_unique(array_column($found[0]['earlier_records'], 'sample'))));
        $this->assertFalse($found[0]['cross_check']['agrees_on_growth']);
    }

    public function testTheBookstackAnswersAreInEnglishAndTwoOverlappingRoutesKeepTheirEarlierEntries(): void
    {
        $truth = Truth::load(dirname(__DIR__, 3) . '/benchmarks/real-apps/truth.json');

        $new = array_values(array_filter($truth['routes'], static fn (array $r): bool => in_array('round3-bookstack', $r['sets'], true)));
        // 44 routes were answered; two of them were already answered in the earlier sample under the same id, which stays as it was
        $this->assertCount(42, $new);

        $growth = [];
        foreach ($new as $route) {
            $this->assertSame('bookstack', $route['app']);
            $this->assertSame(['query'], array_keys($route['axes']), $route['id']);
            $q = $route['axes']['query'];
            $growth[$q['growth']] = ($growth[$q['growth']] ?? 0) + 1;
            $this->assertContains($q['confidence'], ['high', 'medium'], $route['id']);
            $this->assertNotEmpty($route['evidence'], $route['id']);
            if ($q['growth'] === 'conditional') {
                $this->assertNotSame('', trim((string) ($q['condition'] ?? '')), $route['id'] . ' needs the condition in words');
                $this->assertContains($q['growth_if_condition_degree'], [1, 2], $route['id']);
            }
            $this->assertSame(0, preg_match('/[\x{3000}-\x{30ff}\x{3400}-\x{9fff}\x{ff00}-\x{ffef}]/u', (string) json_encode($route, JSON_UNESCAPED_UNICODE)), $route['id']);
        }
        ksort($growth);
        $this->assertSame(['O(1)' => 13, 'O(N)' => 9, 'O(N*M)' => 1, 'conditional' => 19], $growth);

        // the two overlapping routes are still the earlier entries, in the earlier set only
        foreach (['bookstack PUT /api/pages/{id}', 'bookstack PUT /books/{slug}'] as $id) {
            $found = array_values(array_filter($truth['routes'], static fn (array $r): bool => $r['id'] === $id));
            $this->assertCount(1, $found, $id);
            $this->assertSame(['lower-bound-sample'], $found[0]['sets'], $id);
        }
    }

    public function testTheCommittedAnswersCarryNoEnvironmentDetailsAndNoQuotedCode(): void
    {
        $text = (string) file_get_contents(dirname(__DIR__, 3) . '/benchmarks/real-apps/truth.json');

        // an absolute path starts a string or follows a space; "app/Users/..." inside an app is a relative path
        $this->assertSame(0, preg_match('~(^|["\s])(/home/|/Users/|/mnt/|/tmp/|[A-Za-z]:\\\\)~m', $text));
        $this->assertStringNotContainsString('<?php', $text, 'the apps code is referenced by file:line, never copied');
    }

    public function testTheBenchmarksAreNotPartOfThePackedToolOrItsAutoloader(): void
    {
        $root = dirname(__DIR__, 3);
        $box = (string) file_get_contents($root . '/box.json.dist');
        $composer = (string) file_get_contents($root . '/composer.json');

        $this->assertStringNotContainsString('benchmarks', $box);
        $this->assertStringNotContainsString('benchmarks', $composer);
    }

    /**
     * @param list<array<string, mixed>> $routes
     *
     * @return array<string, mixed>
     */
    private function truth(array $routes): array
    {
        return Truth::parse((string) json_encode([
            'schema' => Truth::SCHEMA,
            'apps' => ['a' => ['commit' => str_repeat('a', 40)], 'b' => ['commit' => str_repeat('b', 40)]],
            'routes' => $routes,
        ]));
    }

    /**
     * @param array<string, mixed> $axes
     * @param list<string> $sets
     *
     * @return array<string, mixed>
     */
    private function route(string $id, string $app, string $method, string $uri, array $axes, array $sets): array
    {
        return ['id' => $id, 'app' => $app, 'method' => $method, 'uri' => $uri, 'sets' => $sets, 'axes' => $axes];
    }
}
