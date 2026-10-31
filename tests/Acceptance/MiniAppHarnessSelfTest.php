<?php

declare(strict_types=1);

namespace PeakSim\Tests\Acceptance;

use PeakSim\Model\AnalysisReport;
use PeakSim\Model\AssumptionCode;
use PeakSim\Model\AxisVerdict;
use PeakSim\Model\Confidence;
use PeakSim\Model\Growth;
use PeakSim\Model\RouteResult;
use PeakSim\Model\Site;
use PeakSim\Model\GapCode;
use PeakSim\Tests\Support\Expectation;
use PeakSim\Tests\Support\ExpectationFixtureRows;
use PeakSim\Tests\Support\MiniAppAnalyzer;
use PeakSim\Tests\Support\MiniAppCase;
use PeakSim\Tests\Support\MiniAppEchoAnalyzer;
use PeakSim\Tests\Support\MiniAppExport;
use PeakSim\Tests\Support\MiniAppRunner;
use PeakSim\Tests\Support\MiniAppWorkspace;
use PeakSim\Tests\Support\ReportAssert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests of the test harness itself. They never touch the tool: a stand-in analyzer plays it.
 * These must be green today; they are what makes a red MiniAppTest mean "the tool is missing", not "the harness is wrong".
 */
final class MiniAppHarnessSelfTest extends TestCase
{
    /** @var list<string> */
    private array $made = [];

    protected function tearDown(): void
    {
        foreach ($this->made as $dir) {
            MiniAppWorkspace::remove($dir);
        }
    }

    private function tmp(): string
    {
        return $this->made[] = MiniAppWorkspace::make('self');
    }

    /**
     * Cases whose three files exist. A case still being written by someone else is left to MiniAppTest, which
     * reports the missing files; it must not turn the tests of the harness red.
     *
     * @return array<string, array{0: string}>
     */
    public static function caseProvider(): array
    {
        $cases = [];
        foreach (MiniAppCase::discover(MiniAppWorkspace::appsDir()) as $case) {
            if (is_file($case->dir . '/expect.php') && is_file($case->dir . '/export.php') && is_file($case->dir . '/SPEC.md')) {
                $cases[$case->name] = [$case->name];
            }
        }

        return $cases;
    }

    /** A tool that says exactly what a case expects makes the case pass: the case's expectation is self-consistent. */
    #[DataProvider('caseProvider')]
    public function testEveryCaseIsSelfConsistentAndPassesAgainstAnAnalyzerThatEchoesIt(string $name): void
    {
        $case = $this->caseNamed($name);
        $this->assertSame([], $case->problems(), "{$name}: the case itself is malformed");

        // The runner demands app/ (written later by someone else); give it a throwaway root that holds only expect.php.
        $problems = $this->verifyWithoutApp($case, new MiniAppEchoAnalyzer());

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    /** @return array<string, array{0: string}> only the cases that expect some growth (nothing to under-report in the others) */
    public static function growingCaseProvider(): array
    {
        $cases = [];
        foreach (self::caseProvider() as [$name]) {
            $case = array_values(array_filter(MiniAppCase::discover(MiniAppWorkspace::appsDir()), static fn (MiniAppCase $c): bool => $c->name === $name))[0];
            foreach ($case->expect()['routes'] as $want) {
                foreach (['query', 'memory'] as $axis) {
                    $label = is_array($want[$axis] ?? null) ? $want[$axis]['growth'] : ($want[$axis] ?? 'O(1)');
                    if (in_array($label, ['O(N)', 'O(N*M)'], true)) {
                        $cases[$case->name] = [$case->name];
                    }
                }
            }
        }

        return $cases;
    }

    /** Answering one degree too light must be noticed wherever a case expects a growth. */
    #[DataProvider('growingCaseProvider')]
    public function testAnAnalyzerThatUnderReportsFailsEveryCaseThatExpectsAGrowth(string $name): void
    {
        $problems = $this->verifyWithoutApp($this->caseNamed($name), new MiniAppEchoAnalyzer(lighterBy: 1));

        $this->assertNotSame([], $problems, "{$name}: a tool that reports one degree too light went unnoticed");
    }

    /** Codes named in expect.php must exist in the frozen enums (a typo would otherwise make a case unsatisfiable). */
    #[DataProvider('caseProvider')]
    public function testEveryGapAndAssumptionCodeInAnExpectationExists(string $name): void
    {
        $invalid = [];
        foreach ($this->caseNamed($name)->expect()['routes'] as $uri => $want) {
            foreach (array_merge($want['gaps'] ?? [], $want['gaps_include'] ?? [], $want['gaps_any'] ?? []) as $code) {
                if (GapCode::tryFrom($code) === null) {
                    $invalid[] = "{$uri}: {$code} is not a GapCode";
                }
            }
            foreach (array_merge($want['assumptions'] ?? [], $want['assumptions_include'] ?? []) as $code) {
                if (AssumptionCode::tryFrom($code) === null) {
                    $invalid[] = "{$uri}: {$code} is not an AssumptionCode";
                }
            }
        }

        $this->assertSame([], $invalid, "{$name}: " . implode('; ', $invalid));
    }

    /** @return array<string, array{0: string}> */
    public static function fixtureRouteProvider(): array
    {
        $cases = [];
        foreach (array_keys(ExpectationFixtureRows::rows()) as $uri) {
            $cases[$uri] = [$uri];
        }

        return $cases;
    }

    /** The 24-route comparison agrees with a tool that says what expected.json says. */
    #[DataProvider('fixtureRouteProvider')]
    public function testTheRouteComparisonAcceptsAnAnalyzerThatEchoesExpectedJson(string $uri): void
    {
        $row = ExpectationFixtureRows::rows()[$uri];
        $view = (new MiniAppEchoAnalyzer())->analyzeRoute(MiniAppWorkspace::fixtureApp(), null, $uri);

        $result = ExpectationFixtureRows::compare($row, $view);

        $this->assertSame([], $result['problems'], implode("\n", $result['problems']));
        $this->assertSame([], ReportAssert::invariants($view), $uri);
        $this->assertNotContains('missed', [$result['query'], $result['memory']]);
    }

    /** ...and it names a miss when the tool is one degree too light on a route that grows. */
    public function testTheRouteComparisonReportsAMissForALightAnalyzer(): void
    {
        $misses = 0;
        foreach (ExpectationFixtureRows::rows() as $uri => $row) {
            $view = (new MiniAppEchoAnalyzer(lighterBy: 1))->analyzeRoute(MiniAppWorkspace::fixtureApp(), null, $uri);
            $result = ExpectationFixtureRows::compare($row, $view);
            $grows = in_array('O(N)', [$row['query'], $row['memory'], $row['query_on_miss']], true) || in_array('O(N*M)', [$row['query'], $row['memory']], true);
            if ($grows) {
                $this->assertNotSame([], $result['problems'], "{$uri}: a light verdict went unnoticed");
                $this->assertContains('missed', [$result['query'], $result['memory']], $uri);
                ++$misses;
            }
        }
        $this->assertGreaterThanOrEqual(15, $misses);
    }

    /** A real RouteResult in the Judge's frozen form (and the older bare-Site form) is read without a TypeError. */
    public function testViewReadsTheJudgesFrozenEvidenceAndWorstCaseAndAlsoABareSite(): void
    {
        $axis = new AxisVerdict(Growth::Linear, 1, Confidence::High, null, null);
        $result = static fn (array $evidence, ?array $worst): RouteResult => new RouteResult(
            '/t', ['GET'], 'A@b', RouteResult::KIND_CONTROLLER, $axis, $axis, 'orders', null, null, Confidence::High, null, $worst,
            ['query_coefficient' => null, 'per_item_queries' => null, 'extra_queries_per_request' => null], [], [], $evidence, 2, false, ['customers'],
        );
        $frozen = $result(
            [['role' => 'call', 'site' => new Site('app/A.php', 4), 'axis' => null, 'note' => ''], ['role' => 'event', 'site' => new Site('app/A.php', 9, 'n'), 'axis' => 'query', 'note' => 'lazy_load customers']],
            [['site' => new Site('app/P.php', 7), 'abstract' => 'App\\Contract', 'candidates' => ['X', 'Y'], 'chosen' => 'Y']],
        );

        $view = ReportAssert::view($frozen);

        $this->assertSame([
            ['role' => 'call', 'file' => 'app/A.php', 'line' => 4, 'axis' => null, 'note' => ''],
            ['role' => 'event', 'file' => 'app/A.php', 'line' => 9, 'axis' => 'query', 'note' => 'lazy_load customers'],
        ], $view['evidence']);
        $this->assertSame([['file' => 'app/P.php', 'line' => 7, 'abstract' => 'App\\Contract', 'candidates' => ['X', 'Y'], 'chosen' => 'Y']], $view['worst_case']);
        $this->assertTrue($view['worst_case_adopted']);
        $this->assertSame(['customers'], $view['n_table_alternatives']);
        $this->assertSame([], Expectation::check(['routes' => ['/t' => ['evidence_include' => ['app/A.php:9', 'app/A.php']]]], ['/t' => $view]));
        $this->assertCount(1, Expectation::check(['routes' => ['/t' => ['evidence_include' => ['app/A.php:10']]]], ['/t' => $view]));

        $bare = ReportAssert::view($result([new Site('app/B.php', 3)], null));
        $this->assertSame([['role' => 'event', 'file' => 'app/B.php', 'line' => 3, 'axis' => null, 'note' => '']], $bare['evidence']);
        $this->assertFalse($bare['worst_case_adopted']);
        $this->assertStringContainsString('"n_table_alternatives"', ReportAssert::json(new AnalysisReport([$frozen], [], [])));
    }

    public function testTheRenderedJsonOfTheStandInDiffersOnlyInInputMode(): void
    {
        $echo = new MiniAppEchoAnalyzer();
        $a = $echo->renderedJson(MiniAppWorkspace::fixtureApp(), null, 'export-dir');
        $b = $echo->renderedJson(MiniAppWorkspace::fixtureApp(), null, 'artisan-command');

        $this->assertNotSame($a, $b);
        $this->assertSame(str_replace('"mode": "export-dir"', '"mode": "artisan-command"', $a), $b);
    }

    public function testDiscoveryFindsCasesByExpectationAndSkipsKitHiddenAndSourceOnlyDirectories(): void
    {
        $root = $this->tmp();
        foreach (['b-case', 'a-case', '_kit', '.hidden', 'src-only', 'empty'] as $dir) {
            mkdir($root . '/' . $dir);
        }
        foreach (['b-case', 'a-case', '_kit', '.hidden'] as $dir) {
            file_put_contents($root . '/' . $dir . '/expect.php', "<?php return [];\n");
        }
        file_put_contents($root . '/src-only/SPEC.md', '#');
        file_put_contents($root . '/stray.txt', 'x');

        $names = array_map(static fn (MiniAppCase $c): string => $c->name, MiniAppCase::discover($root));

        $this->assertSame(['a-case', 'b-case'], $names, 'a directory without expect.php is not a case; _ and . prefixes are never cases');
    }

    /** Leaving a directory out for lack of expect.php must only ever happen to a source-index (src-*) directory. */
    public function testOnlySourceDirectoriesMayLackAnExpectation(): void
    {
        $left = [];
        foreach (scandir(MiniAppWorkspace::appsDir()) ?: [] as $entry) {
            $dir = MiniAppWorkspace::appsDir() . '/' . $entry;
            if ($entry[0] !== '.' && $entry[0] !== '_' && is_dir($dir) && !is_file($dir . '/expect.php') && !str_starts_with($entry, 'src-')) {
                $left[] = $entry;
            }
        }

        $this->assertSame([], $left, 'directories that look like cases but have no expect.php (they would silently never run)');
    }

    public function testACaseMissingItsOtherFilesIsReportedAsMalformed(): void
    {
        $root = $this->tmp();
        mkdir($root . '/lonely');
        file_put_contents($root . '/lonely/expect.php', "<?php return ['description' => 'd', 'routes' => []];\n");
        $case = MiniAppCase::discover($root)[0];

        $problems = $case->problems();

        $this->assertCount(2, $problems);
        $this->assertStringContainsString('export.php is missing', $problems[0]);
        $this->assertStringContainsString('SPEC.md is missing', $problems[1]);
    }

    public function testExpectationNamingARouteThatTheExportDoesNotRouteIsMalformed(): void
    {
        $root = $this->tmp();
        mkdir($root . '/c');
        file_put_contents($root . '/c/SPEC.md', '#');
        file_put_contents($root . '/c/export.php', "<?php\nuse PeakSim\\Tests\\Support\\MiniAppExport as E;\nreturn E::build(routes: [E::route('/a', 'X@y')]);\n");
        file_put_contents($root . '/c/expect.php', "<?php\nreturn ['description' => 'd', 'routes' => ['/b' => ['query' => 'O(1)']]];\n");

        $problems = MiniAppCase::discover($root)[0]->problems();

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('/b', $problems[0]);
    }

    public function testExpectationCheckAgreesWithAMatchingViewAndNamesEveryDifference(): void
    {
        $view = $this->view();
        $this->assertSame([], Expectation::check(['routes' => ['/t' => ['query' => 'O(N)', 'memory' => ['growth' => 'O(N)', 'degree' => 1], 'gaps' => [], 'n_table' => 'orders', 'events_found' => '>=1']]], ['/t' => $view]));

        $problems = Expectation::check(['routes' => ['/t' => [
            'query' => 'O(1)', 'memory' => ['confidence' => 'medium'], 'gaps' => ['NO_BINDING'], 'n_table' => 'customers',
            'events_found' => 0, 'truncated' => true, 'worst_case_adopted' => true, 'assumptions_include' => ['BRANCH_WORST_CASE'],
            'cache' => ['on_miss' => ['query' => 'O(N)']], 'evidence_include' => ['app/Nope.php:9'],
        ]]], ['/t' => $view]);

        $this->assertCount(10, $problems, implode("\n", $problems));
    }

    public function testExpectationCheckCatchesTypoedKeysMissingAndUnexpectedRoutes(): void
    {
        $problems = Expectation::check(['routes' => ['/t' => ['qeury' => 'O(1)'], '/missing' => []]], ['/t' => $this->view(), '/extra' => $this->view('/extra')]);

        $this->assertCount(3, $problems, implode("\n", $problems));
        $this->assertStringContainsString("unknown expectation key 'qeury'", $problems[0]);
        $this->assertStringContainsString('/missing', $problems[1]);
        $this->assertStringContainsString('/extra', $problems[2]);
    }

    public function testGapsAnyAcceptsAnyOneOfTheListedCodes(): void
    {
        $view = $this->view();
        $view['gaps'] = [['code' => 'NO_BINDING', 'file' => 'a', 'line' => 1, 'note' => '', 'message' => '', 'affects' => ['query', 'memory'], 'loop_depth' => 0]];

        $this->assertSame([], Expectation::check(['routes' => ['/t' => ['gaps_any' => ['VENDOR_CALL_NOT_MODELLED', 'NO_BINDING']]]], ['/t' => $view]));
        $this->assertCount(1, Expectation::check(['routes' => ['/t' => ['gaps_any' => ['DEPTH_LIMIT', 'RECURSION']]]], ['/t' => $view]));
    }

    public function testExpectationCheckRefusesAnEmptyRoutesList(): void
    {
        $this->assertCount(1, Expectation::check(['routes' => []], []));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function violationProvider(): array
    {
        return [
            'unknown without any gap' => [['query' => ['growth' => 'unknown', 'degree' => 0, 'confidence' => null, 'lower_bound' => null], 'confidence' => 'high'], 'unknown with no gap'],
            'a confidence on an unknown axis' => [['query' => ['growth' => 'unknown', 'degree' => 0, 'confidence' => 'high', 'lower_bound' => null], 'gaps' => [['code' => 'X', 'file' => 'a', 'line' => 1, 'affects' => ['query']]]], 'carries a confidence'],
            'no confidence on a finite axis' => [['memory' => ['growth' => 'O(N)', 'degree' => 1, 'confidence' => null, 'lower_bound' => null]], 'has no confidence'],
            'degree and label disagree' => [['memory' => ['growth' => 'O(N)', 'degree' => 2, 'confidence' => 'high', 'lower_bound' => null]], 'does not fit label'],
            'route confidence is not the lower axis' => [['memory' => ['growth' => 'O(N)', 'degree' => 1, 'confidence' => 'medium', 'lower_bound' => null], 'confidence' => 'high'], 'lower of the two axes'],
            'route confidence kept while one axis is unknown' => [['query' => ['growth' => 'unknown', 'degree' => 0, 'confidence' => null, 'lower_bound' => null], 'gaps' => [['code' => 'X', 'file' => 'a', 'line' => 1, 'affects' => ['query']]], 'confidence' => 'high'], 'must be null'],
            'nothing found, nothing missed, yet not O(1)' => [['events_found' => 0, 'memory' => ['growth' => 'O(N)', 'degree' => 1, 'confidence' => 'high', 'lower_bound' => null]], 'both axes must be O(1)'],
            'truncated without DEPTH_LIMIT' => [['truncated' => true], 'DEPTH_LIMIT'],
            'worst case without its assumption' => [['worst_case_adopted' => true], 'WORST_CASE_OF_CANDIDATES'],
            'absolute path in evidence' => [['evidence' => [['file' => '/srv/app/X.php', 'line' => 1]]], 'absolute path'],
        ];
    }

    /** @param array<string, mixed> $patch */
    #[DataProvider('violationProvider')]
    public function testInvariantsCatchEveryKindOfInconsistentVerdict(array $patch, string $fragment): void
    {
        $violations = ReportAssert::invariants(array_replace($this->view(), $patch));

        $this->assertNotSame([], $violations);
        $this->assertStringContainsString($fragment, implode("\n", $violations));
    }

    public function testInvariantsAcceptAConsistentVerdict(): void
    {
        $this->assertSame([], ReportAssert::invariants($this->view()));
    }

    public function testRankingTreatsUnknownAsTheWorstAndComparesInTheRightDirection(): void
    {
        $this->assertSame('match', ReportAssert::compare('O(N)', 'O(N)'));
        $this->assertSame('over', ReportAssert::compare('O(1)', 'O(N)'));
        $this->assertSame('missed', ReportAssert::compare('O(N)', 'O(1)'));
        $this->assertSame('missed', ReportAssert::compare('unknown', 'O(N*M)'));
        $this->assertSame('over', ReportAssert::compare('O(N*M)', 'unknown'));
    }

    public function testExportWriterProducesTheLayoutOfTheRealArtisanExport(): void
    {
        $dir = $this->tmp();
        MiniAppExport::writeTo(MiniAppExport::build([MiniAppExport::route('/orders', 'A@b'), MiniAppExport::route('/', 'A@c')], MiniAppExport::standardModels()), $dir);

        $routes = json_decode(file_get_contents($dir . '/route-list.json'), true, 512, JSON_THROW_ON_ERROR);
        $real = json_decode(file_get_contents(MiniAppWorkspace::fixtureExport() . '/route-list.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(array_keys($real[0]), array_keys($routes[0]), 'route rows must have the keys artisan prints');
        $this->assertSame('orders', $routes[0]['uri'], 'artisan prints the uri without the leading slash');
        $this->assertSame('/', $routes[1]['uri'], 'the root route stays "/"');

        $generated = json_decode(file_get_contents($dir . '/model-show/Order.json'), true, 512, JSON_THROW_ON_ERROR);
        $realModel = json_decode(file_get_contents(MiniAppWorkspace::fixtureExport() . '/model-show/Order.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(array_keys($realModel), array_keys($generated), 'model documents must have the keys artisan prints');
        $this->assertSame(array_keys($realModel['attributes'][0]), array_keys($generated['attributes'][0]));
        $this->assertSame(array_keys($realModel['relations'][0]), array_keys($generated['relations'][0]));
        $this->assertSame(['Customer.json', 'Item.json', 'Order.json', 'Product.json'], array_values(array_diff(scandir($dir . '/model-show'), ['.', '..'])));
    }

    public function testWorkspaceCopiesAppsLeavingOutVendorAndRefusesToDeleteForeignDirectories(): void
    {
        $src = $this->tmp();
        mkdir($src . '/vendor');
        mkdir($src . '/app');
        file_put_contents($src . '/vendor/x.php', 'x');
        file_put_contents($src . '/app/y.php', 'y');

        $copy = MiniAppWorkspace::copyApp($src);
        $this->made[] = $copy;
        $this->assertFileExists($copy . '/app/y.php');
        $this->assertFileDoesNotExist($copy . '/vendor/x.php');

        $foreign = sys_get_temp_dir() . '/not-ours-' . bin2hex(random_bytes(4));
        mkdir($foreign);
        MiniAppWorkspace::remove($foreign);
        $this->assertDirectoryExists($foreign, 'a directory without our prefix must survive remove()');
        rmdir($foreign);
    }

    private function caseNamed(string $name): MiniAppCase
    {
        foreach (MiniAppCase::discover(MiniAppWorkspace::appsDir()) as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }
        $this->fail("case {$name} disappeared");
    }

    /** @return list<string> */
    private function verifyWithoutApp(MiniAppCase $case, MiniAppAnalyzer $analyzer): array
    {
        // Same path as MiniAppRunner::verify(), minus the "app/ must exist" gate.
        $expect = $case->expect();
        $exportDir = $this->tmp();
        MiniAppExport::writeTo($case->export(), $exportDir);
        $views = $analyzer->analyzeAll($case->dir, $exportDir, $expect['config'] ?? []);
        $problems = Expectation::check($expect, $views);
        foreach ($views as $view) {
            $problems = array_merge($problems, ReportAssert::invariants($view));
        }

        return $problems;
    }

    /** @return array<string, mixed> */
    private function view(string $uri = '/t'): array
    {
        $axis = ['growth' => 'O(N)', 'degree' => 1, 'confidence' => 'high', 'lower_bound' => null];

        return [
            'uri' => $uri, 'methods' => ['GET'], 'action' => 'A@b', 'kind' => 'controller', 'query' => $axis, 'memory' => $axis,
            'n_table' => 'orders', 'm_table' => null, 'm_per' => null, 'confidence' => 'high', 'cache' => null,
            'worst_case_adopted' => false, 'worst_case' => null, 'coefficients' => [], 'gaps' => [], 'assumptions' => [],
            'evidence' => [['file' => 'app/X.php', 'line' => 3, 'note' => '']], 'events_found' => 2, 'truncated' => false,
        ];
    }
}
