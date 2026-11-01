<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Output;

use PeakSim\Model\AssumptionCode;
use PeakSim\Model\GapCode;
use PeakSim\Output\JsonRenderer;
use PHPUnit\Framework\TestCase;

/**
 * docs/output-schema.md must not drift from the code it describes: the enum tables list exactly the cases of
 * the enums, and the schema version in the text is the one the renderer writes.
 */
final class SchemaDocTest extends TestCase
{
    private function doc(): string
    {
        $doc = file_get_contents(dirname(__DIR__, 3) . '/docs/output-schema.md');
        $this->assertNotFalse($doc, 'docs/output-schema.md is missing');

        return $doc;
    }

    /** @return list<string> the first-column codes of the table under a "### <heading>" */
    private function tableCodes(string $heading): array
    {
        $this->assertSame(1, preg_match('/^### ' . preg_quote($heading, '/') . '\n(.*?)(?=^#{1,3} |\z)/ms', $this->doc(), $section), "no section {$heading}");
        preg_match_all('/^\| `([^`]+)` \|/m', $section[1], $codes);

        return $codes[1];
    }

    public function testTheGapCodeTableListsExactlyTheCasesOfTheEnum(): void
    {
        $documented = $this->tableCodes('gap コード');
        $actual = array_map(static fn (GapCode $c): string => $c->value, GapCode::cases());

        $this->assertSame([], array_values(array_diff($actual, $documented)), 'gap codes missing from the document');
        $this->assertSame([], array_values(array_diff($documented, $actual)), 'gap codes in the document that no longer exist');
        $this->assertSame(count($actual), count($documented), 'a code is listed twice');
    }

    public function testTheAssumptionCodeTableListsExactlyTheCasesOfTheEnum(): void
    {
        $documented = $this->tableCodes('assumption コード');
        $actual = array_map(static fn (AssumptionCode $c): string => $c->value, AssumptionCode::cases());

        $this->assertSame([], array_values(array_diff($actual, $documented)));
        $this->assertSame([], array_values(array_diff($documented, $actual)));
        $this->assertSame(count($actual), count($documented));
    }

    public function testTheDocumentedSchemaVersionIsTheOneTheRendererWrites(): void
    {
        $this->assertStringContainsString('現在は `"' . JsonRenderer::SCHEMA_VERSION . '"`', $this->doc());
    }

    public function testTheDocumentSaysThatDegreeIsNullForAnUnknownAxisWithNoLowerBound(): void
    {
        $this->assertMatchesRegularExpression('/\| `degree` \| int \| null \|[^\n]*`lower_bound` が `null`[^\n]*`null`/u', $this->doc());
        $this->assertStringContainsString('unknown (no lower bound)', $this->doc());
    }

    public function testTheRevisionHistoryNamesVersionZeroPointTwo(): void
    {
        $this->assertMatchesRegularExpression('/^- .*`0\.2`.*degree.*null/mu', $this->doc());
    }

    public function testNoExampleInTheDocumentShowsAnUnknownAxisWithADegreeOfZero(): void
    {
        $this->assertDoesNotMatchRegularExpression('/"growth": "unknown",\s*"degree": 0,\s*"confidence": null,\s*"lower_bound": null/u', $this->doc());
    }

    public function testTheDocumentSaysThatStaticUnknownIsNotTheMeasuredUndecidable(): void
    {
        $this->assertMatchesRegularExpression('/`unknown`.*別物/su', $this->doc());
    }

    public function testTheDocumentNamesNoMachine(): void
    {
        foreach (['/home/', '/Users/', 'localhost', '@gmail'] as $machine) {
            $this->assertStringNotContainsString($machine, $this->doc());
        }
    }

    public function testTheTopLevelAndRouteKeysInTheDocumentAreTheOnesTheRendererWrites(): void
    {
        $json = (new JsonRenderer())->render(ReportBuilder::report(ReportBuilder::inputSpec()), 'export-dir');
        $doc = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        foreach (array_keys($doc) as $key) {
            $this->assertStringContainsString("| `{$key}` |", $this->doc(), "top-level key {$key} is not documented");
        }
        foreach (array_keys($doc['routes'][0]) as $key) {
            $this->assertStringContainsString("| `{$key}` |", $this->doc(), "route key {$key} is not documented");
        }
    }
}
