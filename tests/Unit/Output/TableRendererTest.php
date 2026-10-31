<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Output;

use PeakSim\Model\AnalysisReport;
use PeakSim\Name;
use PeakSim\Output\TableRenderer;
use PHPUnit\Framework\TestCase;

final class TableRendererTest extends TestCase
{
    private function render(array $spec): string
    {
        return (new TableRenderer())->render(ReportBuilder::report($spec));
    }

    /** @return list<string> */
    private function lines(string $table): array
    {
        return explode("\n", rtrim($table, "\n"));
    }

    public function testTheGoldenTableComesOutByteForByte(): void
    {
        $this->assertSame(ReportBuilder::golden('report.table.txt'), $this->render(ReportBuilder::inputSpec()));
    }

    public function testTheEmptyReportHasNoTableAndAZeroSummary(): void
    {
        $this->assertSame(ReportBuilder::golden('empty.table.txt'), (new TableRenderer())->render(new AnalysisReport([], [], [])));
    }

    public function testTheSameReportAndAReorderedOneGiveTheSameTable(): void
    {
        $spec = ReportBuilder::inputSpec();

        $this->assertSame($this->render($spec), $this->render($spec));
        $this->assertSame($this->render($spec), $this->render(ReportBuilder::shuffled($spec)));
    }

    public function testTheColumnsAreTheDocumentedOnesInOrder(): void
    {
        $header = $this->lines($this->render(ReportBuilder::inputSpec()))[0];

        $this->assertSame(['ROUTE', 'QUERY', 'MEMORY', 'N(M)', 'CONFIDENCE', 'LOWER BOUND', 'GAPS'], preg_split('/\s{2,}/', $header));
    }

    public function testEveryRowStartsEachColumnAtTheSameDisplayColumn(): void
    {
        $lines = $this->lines($this->render(ReportBuilder::inputSpec()));
        $separator = $lines[1];
        $this->assertMatchesRegularExpression('/^-+(  -+){6}$/', $separator);
        preg_match_all('/-+/', $separator, $m, PREG_OFFSET_CAPTURE);
        $starts = array_column($m[0], 1);

        foreach (array_slice($lines, 2, 11) as $row) {
            $this->assertSame(self::width($row), self::width(rtrim($row)), 'trailing spaces');
            foreach (array_slice($starts, 1) as $start) {
                $this->assertSame('  ', self::slice($row, $start - 2, 2), "a column does not start at {$start} in: {$row}");
            }
        }
    }

    public function testRowsAreInUriOrderWithTheMethodShownOnlyWhenItIsNotAGet(): void
    {
        $rows = array_map(static fn (string $l): string => preg_split('/\s{2,}/', $l)[0], array_slice($this->lines($this->render(ReportBuilder::inputSpec())), 2, 11));

        $this->assertSame([
            '/deep/triple', '/hidden/cache', '/hidden/conditional-binding', '/hidden/half-unknown', '/hidden/lower-bound', '/hidden/model-with',
            '/ng/n-plus-one', '/ng/nested', 'POST /ok/bulk', '/ok/chunk', '/unknown/dynamic',
        ], $rows);
    }

    public function testAnUnknownAxisShowsItsLowerBoundInParentheses(): void
    {
        $row = $this->rowOf('/hidden/lower-bound');

        $this->assertStringContainsString('unknown (≥O(N))', $row);
        $this->assertStringContainsString('unknown (≥O(N*M))', $row);
        $this->assertStringContainsString('query ≥O(N), memory ≥O(N*M)', $row);
        $this->assertStringNotContainsString('(≥', $this->rowOf('/unknown/dynamic'), 'no lower bound, nothing in parentheses');
    }

    public function testADegreeOfThreeOrMoreIsShownAsNestedGrowthTimesTheDegree(): void
    {
        $this->assertStringContainsString('O(N*M)×3', $this->rowOf('/deep/triple'));
        $this->assertStringNotContainsString('×', $this->rowOf('/ng/nested'), 'degree 2 is plain O(N*M)');
    }

    public function testACachedRouteShowsMissAndHitAndTheCacheTag(): void
    {
        $row = $this->rowOf('/hidden/cache');

        $this->assertStringContainsString('O(N) miss / O(1) hit [cache]', $row);
    }

    public function testAWorstCaseVerdictSaysHowManyCandidatesWereTaken(): void
    {
        $this->assertStringContainsString('[worst-case of 2]', $this->rowOf('/hidden/conditional-binding'));
    }

    public function testAnUnknownRouteHasADashForConfidence(): void
    {
        $cells = preg_split('/\s{2,}/', $this->rowOf('/unknown/dynamic'));

        $this->assertSame(['/unknown/dynamic', 'unknown', 'unknown', '—', '—', '—', 'DYNAMIC_METHOD_NAME'], $cells);
    }

    public function testARouteWithOneUnknownAxisHasADashForConfidenceAndTheBoundOfThatAxisOnly(): void
    {
        $cells = preg_split('/\s{2,}/', $this->rowOf('/hidden/half-unknown'));

        $this->assertSame(['/hidden/half-unknown', 'O(N)', 'unknown (≥O(N))', 'orders', '—', 'memory ≥O(N)', 'UNRESOLVED_CALL'], $cells);
    }

    public function testTheOutputHasNoJapanese(): void
    {
        $this->assertDoesNotMatchRegularExpression('/[\p{Han}\p{Hiragana}\p{Katakana}]/u', $this->render(ReportBuilder::inputSpec()));
        $this->assertDoesNotMatchRegularExpression('/[\p{Han}\p{Hiragana}\p{Katakana}]/u', (new TableRenderer())->render(new AnalysisReport([], [], [])));
    }

    public function testTheNestedTableIsNamedWithItsPer(): void
    {
        $this->assertStringContainsString('orders (M: items per orders)', $this->rowOf('/ng/nested'));
    }

    public function testRoutesThatAreNotControllersAreNotRowsButAreCounted(): void
    {
        $table = $this->render(ReportBuilder::inputSpec());

        $this->assertStringNotContainsString('/framework/health', $table);
        $this->assertStringContainsString('11 routes analysed, 3 not analysed (framework / closure)', $table);
    }

    public function testTheSummaryNamesTheToolFromTheOnePlaceAndCountsConfidenceAndGaps(): void
    {
        $lines = $this->lines($this->render(ReportBuilder::inputSpec()));
        $summary = array_slice($lines, -3);

        $this->assertStringStartsWith(Name::TOOL_NAME . ': ', $summary[0]);
        $this->assertSame('confidence: high 7, medium 1, — 3', $summary[1]);
        $this->assertSame('gaps: DYNAMIC_METHOD_NAME in 1 route, UNRESOLVED_CALL in 2 routes, VENDOR_CALL_NOT_MODELLED in 1 route', $summary[2]);
        $this->assertSame('', $lines[count($lines) - 4], 'a blank line before the summary');
    }

    public function testOneRouteIsSingular(): void
    {
        $spec = ReportBuilder::inputSpec();
        $spec['routes'] = array_values(array_filter($spec['routes'], static fn (array $r): bool => $r['uri'] === '/ok/chunk'));
        $spec['skipped'] = [];

        $this->assertStringContainsString(Name::TOOL_NAME . ': 1 route analysed, 0 not analysed', $this->render($spec));
        $this->assertStringContainsString('gaps: none', $this->render($spec));
    }

    public function testThereAreNoTrailingSpacesAndTheTextEndsWithOneNewline(): void
    {
        $table = $this->render(ReportBuilder::inputSpec());

        $this->assertDoesNotMatchRegularExpression('/ +\n/', $table);
        $this->assertStringEndsWith("\n", $table);
        $this->assertStringEndsNotWith("\n\n", $table);
    }

    private function rowOf(string $uri): string
    {
        foreach ($this->lines($this->render(ReportBuilder::inputSpec())) as $line) {
            if (str_starts_with($line, $uri . ' ') || str_starts_with($line, 'POST ' . $uri . ' ')) {
                return $line;
            }
        }
        $this->fail("no row for {$uri}");
    }

    /** Display width: East Asian wide characters count 2. */
    private static function width(string $s): int
    {
        return strlen(preg_replace('/[^\x00-\x7F]/u', 'x', $s) ?? '') + preg_match_all('/\p{Han}|\p{Hiragana}|\p{Katakana}/u', $s);
    }

    /** The part of a line between two display columns. */
    private static function slice(string $line, int $from, int $length): string
    {
        $out = '';
        $col = 0;
        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $w = self::width($char);
            if ($col >= $from && $col < $from + $length) {
                $out .= $char;
            }
            $col += $w;
        }

        return $out;
    }
}
