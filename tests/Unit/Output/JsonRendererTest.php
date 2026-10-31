<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Output;

use PeakSim\Model\AnalysisReport;
use PeakSim\Name;
use PeakSim\Output\JsonRenderer;
use PHPUnit\Framework\TestCase;

final class JsonRendererTest extends TestCase
{
    private function render(array $spec, ?string $mode = null): string
    {
        return (new JsonRenderer())->render(ReportBuilder::report($spec), $mode ?? $spec['input_mode']);
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testTheGoldenReportComesOutByteForByte(): void
    {
        $this->assertSame(ReportBuilder::golden('report.json'), $this->render(ReportBuilder::inputSpec()));
    }

    public function testTheEmptyReportComesOutByteForByte(): void
    {
        $json = (new JsonRenderer())->render(new AnalysisReport([], [], []), JsonRenderer::MODE_ARTISAN_COMMAND);

        $this->assertSame(ReportBuilder::golden('empty.json'), $json);
    }

    public function testTheSameReportRendersTheSameBytesTwice(): void
    {
        $spec = ReportBuilder::inputSpec();

        $this->assertSame($this->render($spec), $this->render($spec));
    }

    public function testTheOrderOfTheInputDoesNotChangeTheOutput(): void
    {
        $spec = ReportBuilder::inputSpec();

        $this->assertSame($this->render($spec), $this->render(ReportBuilder::shuffled($spec)));
        $this->assertSame(ReportBuilder::golden('report.json'), $this->render(ReportBuilder::shuffled($spec)));
    }

    public function testRoutesComeOutInUriOrderAndSkippedEntriesInRouteOrder(): void
    {
        $doc = $this->decode($this->render(ReportBuilder::inputSpec()));

        $uris = array_column($doc['routes'], 'route');
        $sorted = $uris;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $uris);
        $this->assertSame(['storage/{path}', 'up'], array_column($doc['skipped'], 'route'));
    }

    public function testTheKeysAreInTheDocumentedOrder(): void
    {
        $doc = $this->decode($this->render(ReportBuilder::inputSpec()));

        $this->assertSame(['schema_version', 'tool', 'input', 'analysis_scope', 'summary', 'skipped', 'routes'], array_keys($doc));
        $this->assertSame(['routes', 'by_confidence', 'by_growth', 'gap_reasons', 'skipped'], array_keys($doc['summary']));
        $this->assertSame(['high', 'medium', 'none'], array_keys($doc['summary']['by_confidence']));
        $this->assertSame(['query', 'memory'], array_keys($doc['summary']['by_growth']));
        $this->assertSame(['O(1)', 'O(N)', 'O(N*M)', 'unknown'], array_keys($doc['summary']['by_growth']['query']));
        $route = $doc['routes'][0];
        $this->assertSame([
            'route', 'methods', 'action', 'kind', 'query', 'memory', 'n_table', 'm_table', 'm_per', 'confidence', 'cache',
            'worst_case', 'coefficients', 'gaps', 'assumptions', 'evidence', 'events_found', 'truncated',
        ], array_keys($route));
        $this->assertSame(['growth', 'degree', 'confidence', 'lower_bound'], array_keys($route['query']));
        $this->assertSame(['query_coefficient', 'per_item_queries', 'extra_queries_per_request'], array_keys($route['coefficients']));
        $withGap = $this->routeNamed($doc, '/hidden/lower-bound');
        $this->assertSame(['code', 'site', 'message', 'affects', 'loop_depth', 'scenario'], array_keys($withGap['gaps'][0]));
        $this->assertSame(['file', 'line', 'note'], array_keys($withGap['gaps'][0]['site']));
        $cached = $this->routeNamed($doc, '/hidden/cache');
        $this->assertSame(['sites', 'on_miss', 'on_hit', 'restores_all_on_hit', 'adopted_view'], array_keys($cached['cache']));
        $this->assertSame(['query', 'memory'], array_keys($cached['cache']['on_hit']));
        $this->assertSame(['code', 'site', 'message', 'affects', 'scenario', 'candidates', 'chosen'], array_keys($cached['assumptions'][0]));
        $this->assertSame(['cache', 'side'], array_keys($cached['assumptions'][0]['scenario'][0]));
        $worst = $this->routeNamed($doc, '/hidden/conditional-binding');
        $this->assertSame(['site', 'abstract', 'candidates', 'chosen'], array_keys($worst['worst_case'][0]));
        $this->assertSame(['role', 'site', 'axis', 'note'], array_keys($worst['evidence'][0]));
    }

    public function testAnUnknownVerdictCarriesNoConfidenceAndNoTable(): void
    {
        $doc = $this->decode($this->render(ReportBuilder::inputSpec()));
        $unknown = $this->routeNamed($doc, '/unknown/dynamic');

        $this->assertSame('unknown', $unknown['query']['growth']);
        $this->assertNull($unknown['query']['confidence']);
        $this->assertNull($unknown['memory']['confidence']);
        $this->assertNull($unknown['confidence'], 'both axes unknown: the route has no confidence');
        $this->assertNull($unknown['n_table']);
        $this->assertSame(0, $unknown['events_found']);
        $this->assertSame('DYNAMIC_METHOD_NAME', $unknown['gaps'][0]['code']);
    }

    public function testALowerBoundIsShownNextToTheUnknown(): void
    {
        $doc = $this->decode($this->render(ReportBuilder::inputSpec()));
        $route = $this->routeNamed($doc, '/hidden/lower-bound');

        $this->assertSame('unknown', $route['query']['growth']);
        $this->assertSame('O(N)', $route['query']['lower_bound']);
        $this->assertSame('O(N*M)', $route['memory']['lower_bound']);
    }

    public function testADegreeOfThreeStaysAnIntegerWhileTheLabelStaysNestedGrowth(): void
    {
        $route = $this->routeNamed($this->decode($this->render(ReportBuilder::inputSpec())), '/deep/triple');

        $this->assertSame('O(N*M)', $route['query']['growth']);
        $this->assertSame(3, $route['query']['degree']);
    }

    public function testTheCacheViewAndTheWorstCaseAreCarried(): void
    {
        $doc = $this->decode($this->render(ReportBuilder::inputSpec()));

        $cache = $this->routeNamed($doc, '/hidden/cache')['cache'];
        $this->assertSame('O(N)', $cache['on_miss']['query']['growth']);
        $this->assertSame('O(1)', $cache['on_hit']['query']['growth']);
        $this->assertTrue($cache['restores_all_on_hit']);
        $this->assertSame('miss', $cache['adopted_view']);

        $worst = $this->routeNamed($doc, '/hidden/conditional-binding');
        $this->assertSame('medium', $worst['confidence']);
        $this->assertCount(2, $worst['worst_case'][0]['candidates']);
        $this->assertSame('WORST_CASE_OF_CANDIDATES', $worst['assumptions'][0]['code']);
        $this->assertSame($worst['worst_case'][0]['chosen'], $worst['assumptions'][0]['chosen']);
    }

    public function testNothingIsAFloatAndNothingNamesTheMachine(): void
    {
        $json = $this->render(ReportBuilder::inputSpec());

        $decoded = $this->decode($json);
        array_walk_recursive($decoded, function (mixed $v): void {
            $this->assertFalse(is_float($v), 'a float in the output');
        });
        $this->assertStringNotContainsString('\\/', $json, 'slashes must not be escaped');
        foreach (['/home/', '/Users/', '/tmp/', '/var/', 'localhost'] as $machine) {
            $this->assertStringNotContainsString($machine, $json);
        }
        $this->assertStringEndsWith("}\n", $json);
    }

    public function testNonAsciiTextIsWrittenAsItIs(): void
    {
        $spec = ReportBuilder::inputSpec();
        $spec['routes'][0]['gaps'][0]['message'] = '追えない呼び出し — “動的”';

        $json = $this->render($spec);

        $this->assertStringContainsString('追えない呼び出し — “動的”', $json);
        $this->assertStringNotContainsString('\\u', $json);
    }

    public function testEmptyMapsAreObjectsAndEmptyListsAreArrays(): void
    {
        $json = (new JsonRenderer())->render(new AnalysisReport([], [], []), JsonRenderer::MODE_EXPORT_DIR);

        $this->assertStringContainsString('"gap_reasons": {}', $json);
        $this->assertStringContainsString('"routes": []', $json);
        $this->assertStringContainsString('"not_covered": []', $json);
    }

    public function testTheInputModeIsTheOnlyThingThatDiffersBetweenTheTwoInputs(): void
    {
        $spec = ReportBuilder::inputSpec();
        $a = $this->decode($this->render($spec, JsonRenderer::MODE_EXPORT_DIR));
        $b = $this->decode($this->render($spec, JsonRenderer::MODE_ARTISAN_COMMAND));

        $this->assertSame('export-dir', $a['input']['mode']);
        $this->assertSame('artisan-command', $b['input']['mode']);
        unset($a['input'], $b['input']);
        $this->assertSame($a, $b);
        $this->assertNull($this->decode((new JsonRenderer())->render(new AnalysisReport([], [], [])))['input']['mode']);
    }

    public function testTheToolNameComesFromTheOnePlaceItIsDefined(): void
    {
        $this->assertSame(Name::TOOL_NAME, $this->decode($this->render(ReportBuilder::inputSpec()))['tool']['name']);
    }

    public function testCoefficientsAlwaysHaveTheThreeKeys(): void
    {
        $spec = ReportBuilder::inputSpec();
        $spec['routes'][0]['coefficients'] = [];

        $doc = $this->decode($this->render($spec));

        foreach ($doc['routes'] as $route) {
            $this->assertSame(['query_coefficient', 'per_item_queries', 'extra_queries_per_request'], array_keys($route['coefficients']), $route['route']);
        }
    }

    public function testTheScopeKeepsItsOwnKeysSortedAndAlwaysHasNotCovered(): void
    {
        $report = new AnalysisReport([], [], ['zeta' => ['b', 'a'], 'alpha' => 1]);

        $doc = $this->decode((new JsonRenderer())->render($report));

        $this->assertSame(['not_covered', 'alpha', 'zeta'], array_keys($doc['analysis_scope']));
        $this->assertSame([], $doc['analysis_scope']['not_covered']);
    }

    public function testASkippedEntryMayNameItsRouteByUriAndIsReducedToTheFourKeys(): void
    {
        $report = new AnalysisReport([], [['uri' => 'up', 'methods' => ['GET'], 'action' => 'Closure', 'kind' => 'closure', 'extra' => 'x']], []);

        $doc = $this->decode((new JsonRenderer())->render($report));

        $this->assertSame([['route' => 'up', 'methods' => ['GET'], 'action' => 'Closure', 'kind' => 'closure']], $doc['skipped']);
    }

    public function testAnAbsolutePathInTheResultIsRefusedNotWritten(): void
    {
        foreach (['/home/dev/app/Http/A.php', 'C:\\work\\app\\A.php', '/srv/app/A.php'] as $file) {
            $spec = ReportBuilder::inputSpec();
            $spec['routes'][0]['gaps'][0]['site']['file'] = $file;

            try {
                $this->render($spec);
                $this->fail("{$file} was written to the output");
            } catch (\LogicException $e) {
                $this->assertStringContainsString('absolute path', $e->getMessage());
                $this->assertStringNotContainsString($file, $e->getMessage(), 'the refusal must not repeat the path');
            }
        }
    }

    public function testAnAbsolutePathInTheEvidenceOrTheCacheSitesIsRefusedToo(): void
    {
        $spec = ReportBuilder::inputSpec();
        foreach ($spec['routes'] as $i => $route) {
            if ($route['uri'] === '/hidden/cache') {
                $spec['routes'][$i]['cache']['sites'][0]['file'] = '/srv/app/C.php';
            }
        }
        $this->expectException(\LogicException::class);

        $this->render($spec);
    }

    /** @param array<string, mixed> $doc */
    private function routeNamed(array $doc, string $uri): array
    {
        foreach ($doc['routes'] as $route) {
            if ($route['route'] === $uri) {
                return $route;
            }
        }
        $this->fail("no route {$uri} in the output");
    }
}
