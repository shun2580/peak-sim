<?php

declare(strict_types=1);

namespace PeakSim\Tests\Acceptance\Cli;

use PeakSim\Name;
use PeakSim\Tests\Support\ExpectationFixtureRows;
use PeakSim\Tests\Support\MiniAppWorkspace;

/**
 * Exit code 0 and what is printed: the table by default, the JSON with --format=json, one route with --route,
 * the timing only on standard error with --stats (design 1.13).
 */
final class OutputTest extends CliTestCase
{
    public function testTheTableIsTheDefaultAndListsEveryRouteOfTheFixtureApp(): void
    {
        $result = $this->analyze($this->exportOption());

        $this->assertSame(0, $result->exitCode, $result->stderr);
        $this->assertSame('', $result->stderr, 'a normal run says nothing on standard error');
        foreach (['ROUTE', 'QUERY', 'MEMORY'] as $column) {
            $this->assertStringContainsString($column, $result->stdout);
        }
        foreach (array_keys(ExpectationFixtureRows::rows()) as $uri) {
            $this->assertStringContainsString($uri, $result->stdout, "{$uri} is in the table");
        }
        $this->assertSame($result->stdout, $this->analyze($this->exportOption(), '--format=table')->stdout, '--format=table is the default');
    }

    public function testTheJsonIsADocumentOfTheSchema(): void
    {
        $result = $this->analyze($this->exportOption(), '--format=json');

        $this->assertSame(0, $result->exitCode, $result->stderr);
        $this->assertSame('', $result->stderr);
        $doc = $this->decodeJson($result->stdout);
        $this->assertSame(Name::TOOL_NAME, $doc['tool']['name']);
        $this->assertSame('export-dir', $doc['input']['mode']);
        $uris = array_column($doc['routes'], 'route');
        $expected = array_keys(ExpectationFixtureRows::rows());
        sort($expected, SORT_STRING);
        $this->assertSame($expected, $uris, 'the 24 routes of the fixture app, in URI order');
        $sorted = $uris;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $uris, 'routes come in URI order');
    }

    public function testTheJsonIsTheSameBytesOnEveryRun(): void
    {
        $first = $this->analyze($this->exportOption(), '--format=json');
        $second = $this->analyze($this->exportOption(), '--format=json');

        $this->assertSame(0, $first->exitCode, $first->stderr);
        $this->assertSame($first->stdout, $second->stdout);
    }

    public function testTheJsonHoldsNoAbsolutePathAndNoTime(): void
    {
        $json = $this->analyze($this->exportOption(), '--format=json', '--stats')->stdout;

        $this->assertStringNotContainsString(MiniAppWorkspace::projectRoot(), $json);
        $this->assertStringNotContainsString(MiniAppWorkspace::fixtureApp(), $json);
        $this->assertDoesNotMatchRegularExpression('/\b20\d\d-\d\d-\d\d[T ]\d\d:\d\d/', $json);
    }

    public function testRouteShowsJustThatRouteInTheTable(): void
    {
        $result = $this->analyze($this->exportOption(), '--route=/ng/n-plus-one');

        $this->assertSame(0, $result->exitCode, $result->stderr);
        $this->assertStringContainsString('/ng/n-plus-one', $result->stdout);
        $this->assertStringNotContainsString('/ng/unbounded', $result->stdout);
        $this->assertStringNotContainsString('/ok/paginate', $result->stdout);
    }

    public function testRouteWorksWithoutTheLeadingSlashToo(): void
    {
        $with = $this->analyze($this->exportOption(), '--route=/ng/n-plus-one', '--format=json');
        $without = $this->analyze($this->exportOption(), '--route=ng/n-plus-one', '--format=json');

        $this->assertSame(0, $without->exitCode, $without->stderr);
        $this->assertSame($with->stdout, $without->stdout);
    }

    public function testRouteInJsonHoldsExactlyOneRouteAndItsVerdict(): void
    {
        $result = $this->analyze($this->exportOption(), '--route=/ng/n-plus-one', '--format=json');

        $this->assertSame(0, $result->exitCode, $result->stderr);
        $doc = $this->decodeJson($result->stdout);
        $this->assertCount(1, $doc['routes']);
        $this->assertSame('/ng/n-plus-one', $doc['routes'][0]['route']);
        $this->assertSame('O(N)', $doc['routes'][0]['query']['growth']);
    }

    public function testRouteOnAClosureRouteIsAUsageErrorBecauseNothingWasAnalysed(): void
    {
        $skipped = $this->decodeJson($this->analyze($this->exportOption(), '--format=json')->stdout)['skipped'];
        $this->assertNotSame([], $skipped, 'the fixture app has closure and framework routes');

        $result = $this->analyze($this->exportOption(), '--route=' . $skipped[0]['route']);

        $this->assertSame(2, $result->exitCode, $result->stderr);
        $this->assertSame('', $result->stdout);
        $this->assertStringContainsString($skipped[0]['kind'], $result->stderr, 'it says why: closure or framework');
    }

    public function testRoutesTheFrameworkRegistersAreSkippedNotUnknown(): void
    {
        $doc = $this->decodeJson($this->analyze($this->exportOption(), '--format=json')->stdout);

        $this->assertSame(['/storage/{path}', '/storage/{path}'], array_column($doc['skipped'], 'route'));
        $this->assertSame(['framework', 'framework'], array_column($doc['skipped'], 'kind'));
        $this->assertSame(2, $doc['summary']['skipped']);
        $this->assertSame(24, $doc['summary']['routes'], 'the 24 routes of the fixture app are analysed');
        $this->assertNotContains('/storage/{path}', array_column($doc['routes'], 'route'));
    }

    public function testAClosureOfTheAppIsSkippedAsAClosure(): void
    {
        $dir = MiniAppWorkspace::make('cli-closure-export');
        try {
            file_put_contents($dir . '/route-list.json', json_encode([
                ['domain' => null, 'method' => 'GET|HEAD', 'uri' => 'ping', 'name' => null, 'action' => 'Closure', 'middleware' => [], 'path' => 'routes/web.php:5'],
            ], JSON_THROW_ON_ERROR));
            $result = $this->peakSim(['analyze', $dir, '--export-dir=' . $dir, '--format=json']);

            $this->assertSame(0, $result->exitCode, $result->stderr);
            $doc = $this->decodeJson($result->stdout);
            $this->assertSame([], $doc['routes']);
            $this->assertSame([['route' => '/ping', 'methods' => ['GET', 'HEAD'], 'action' => 'Closure', 'kind' => 'closure']], $doc['skipped']);
        } finally {
            MiniAppWorkspace::remove($dir);
        }
    }

    public function testStatsGoToStandardErrorOnlyAndLeaveStandardOutputAlone(): void
    {
        foreach (['table', 'json'] as $format) {
            $plain = $this->analyze($this->exportOption(), "--format={$format}");
            $withStats = $this->analyze($this->exportOption(), "--format={$format}", '--stats');

            $this->assertSame(0, $withStats->exitCode, $withStats->stderr);
            $this->assertSame($plain->stdout, $withStats->stdout, "{$format}: --stats must not change standard output");
            $this->assertSame('', $plain->stderr);
            $this->assertStringContainsString('elapsed', $withStats->stderr);
            $this->assertStringContainsString('files', $withStats->stderr);
            $this->assertStringContainsString('summaries', $withStats->stderr);
            $this->assertStringContainsString('depth', $withStats->stderr);
        }
    }

    public function testMaxDepthIsPassedOnToTheEngine(): void
    {
        $deep = $this->analyze($this->exportOption(), '--format=json', '--route=/ng/layered');
        $shallow = $this->analyze($this->exportOption(), '--format=json', '--route=/ng/layered', '--max-depth=2');

        $this->assertSame(0, $shallow->exitCode, $shallow->stderr);
        $this->assertNotSame($deep->stdout, $shallow->stdout, 'a depth of 2 cuts the layered route short');
        $route = $this->decodeJson($shallow->stdout)['routes'][0];
        $this->assertTrue($route['truncated']);
        $this->assertContains('DEPTH_LIMIT', array_column($route['gaps'], 'code'));
    }
}
