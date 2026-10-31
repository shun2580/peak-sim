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
 * The 24 routes of fixtures/laravel-app against fixtures/expected.json (read at run time, nothing copied here).
 * One case per route. A route agrees when query, memory and the N(M) tables agree (S-32); a null in expected.json
 * is "not in the table" and is not compared. Coefficients are shown, not compared.
 *
 * At the end the class prints the 一致 / 過検知 / 見逃し table of the 24 routes to standard output (the raw
 * material of report 1).
 */
final class ExpectedRoutesTest extends TestCase
{
    private const LABEL = ['match' => '一致', 'over' => '過検知', 'missed' => '見逃し', 'mismatch' => '不一致', '-' => '-'];

    /** @var array<string, array<string, mixed>> */
    private static array $table = [];

    protected function analyzer(): MiniAppAnalyzer
    {
        return new MiniAppRealAnalyzer();
    }

    /** @return array<string, array{0: string}> */
    public static function routeProvider(): array
    {
        $cases = [];
        foreach (array_keys(ExpectationFixtureRows::rows()) as $uri) {
            $cases[$uri] = [$uri];
        }

        return $cases;
    }

    #[DataProvider('routeProvider')]
    public function testRouteIsJudgedAsExpected(string $uri): void
    {
        $row = ExpectationFixtureRows::rows()[$uri];
        try {
            $view = $this->analyzer()->analyzeRoute(MiniAppWorkspace::fixtureApp(), MiniAppWorkspace::fixtureExport(), $uri);
        } catch (\Throwable $e) {
            self::$table[$uri] = ['row' => $row, 'error' => (new \ReflectionClass($e))->getShortName()];
            throw $e;
        }

        $result = ExpectationFixtureRows::compare($row, $view);
        self::$table[$uri] = ['row' => $row, 'view' => $view, 'result' => $result];
        $problems = array_merge($result['problems'], ReportAssert::invariants($view));

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    public function testTheReportCoversEveryExpectedRouteAndStaysConsistent(): void
    {
        $views = $this->analyzer()->analyzeAll(MiniAppWorkspace::fixtureApp(), MiniAppWorkspace::fixtureExport());

        $missing = array_values(array_diff(array_keys(ExpectationFixtureRows::rows()), array_keys($views)));
        $this->assertSame([], $missing, 'routes of expected.json that the report does not contain');
        foreach ($views as $view) {
            $this->assertSame([], ReportAssert::invariants($view), $view['uri']);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$table === []) {
            return;
        }
        $counts = ['一致' => 0, '過検知' => 0, '見逃し' => 0, '未実行' => 0];
        $lines = [];
        foreach (self::$table as $uri => $entry) {
            if (isset($entry['error'])) {
                $counts['未実行']++;
                $lines[] = sprintf('%-32s 未実行(%s)', $uri, $entry['error']);
                continue;
            }
            $r = $entry['result'];
            $statuses = [$r['query'], $r['memory'], $r['n_table']];
            $overall = in_array('missed', $statuses, true) ? '見逃し' : (array_intersect(['over', 'mismatch'], $statuses) !== [] ? '過検知' : '一致');
            $counts[$overall]++;
            $v = $entry['view'];
            $lines[] = sprintf(
                '%-32s %-6s query %-6s→%-7s memory %-6s→%-7s n/m %-9s coef: %s',
                $uri,
                $overall,
                (string) ($entry['row']['query'] ?? $entry['row']['query_on_miss'] . '(miss)'),
                $v['query']['growth'],
                (string) $entry['row']['memory'],
                $v['memory']['growth'],
                self::LABEL[$r['n_table']],
                self::coefficients($entry['row'], $v),
            );
        }
        fwrite(STDOUT, "\n=== 一致 / 過検知 / 見逃し ({$counts['一致']} / {$counts['過検知']} / {$counts['見逃し']}"
            . ($counts['未実行'] > 0 ? ", 未実行 {$counts['未実行']}" : '') . ") ===\n" . implode("\n", $lines) . "\n");
    }

    /** Coefficients are shown only. A coefficient that expected.json names but the tool did not give is listed as 出せず. */
    private static function coefficients(array $row, array $view): string
    {
        $out = [];
        foreach (['query_coefficient' => 'query_coefficient', 'per_item_queries' => 'per_item_queries', 'query_extra_per_request' => 'extra_queries_per_request'] as $key => $shown) {
            if ($row[$key] === null) {
                continue;
            }
            $actual = $view['coefficients'][$shown] ?? null;
            $out[] = $shown . '=' . ($actual === null ? '出せず' : json_encode($actual));
        }

        return $out === [] ? '-' : implode(' ', $out);
    }
}
