<?php

declare(strict_types=1);

namespace PeakSim\Output;

use PeakSim\Model\AnalysisReport;
use PeakSim\Model\AxisVerdict;
use PeakSim\Model\Growth;
use PeakSim\Model\RouteResult;
use PeakSim\Name;

/**
 * An AnalysisReport as a text table for the terminal, one row per analysed route in uri order, and a three-line
 * summary. Plain text, no colour, no trailing spaces; widths count East Asian wide characters as two columns.
 */
final class TableRenderer
{
    private const HEADER = ['ROUTE', 'QUERY', 'MEMORY', 'N(M)', 'CONFIDENCE', 'LOWER BOUND', 'GAPS'];

    private const NONE = '—';

    public function render(AnalysisReport $report): string
    {
        $summary = ReportSummary::of($report);
        $rows = array_map($this->cells(...), ReportSummary::analysed($report));
        $lines = [];
        if ($rows !== []) {
            array_push($lines, ...$this->table($rows));
            $lines[] = '';
        }
        array_push($lines, ...$this->summaryLines($summary));

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<list<string>> $rows
     * @return list<string>
     */
    private function table(array $rows): array
    {
        $widths = [];
        foreach (array_keys(self::HEADER) as $i) {
            $widths[$i] = max(array_map(static fn (array $row): int => self::width($row[$i]), [self::HEADER, ...$rows]));
        }
        $separator = array_map(static fn (int $w): string => str_repeat('-', $w), $widths);
        $lines = [];
        foreach ([self::HEADER, $separator, ...$rows] as $row) {
            $cells = [];
            foreach ($row as $i => $cell) {
                $cells[] = $cell . str_repeat(' ', $widths[$i] - self::width($cell));
            }
            $lines[] = rtrim(implode('  ', $cells));
        }

        return $lines;
    }

    /** @return list<string> */
    private function cells(RouteResult $r): array
    {
        $query = self::label($r->query);
        $memory = self::label($r->memory);
        $notes = '';
        if ($r->cache !== null) {
            $query = self::missHit(self::label($r->cache->onMiss['query']), self::label($r->cache->onHit['query']));
            $memory = self::missHit(self::label($r->cache->onMiss['memory']), self::label($r->cache->onHit['memory']));
            $notes .= ' [cache]';
        }
        if ($r->worstCase !== null && $r->worstCase !== []) {
            $notes .= ' [worst-case of ' . count($r->worstCase[0]['candidates']) . ']';
        }

        $table = $r->nTable ?? self::NONE;
        if ($r->nTable !== null && $r->mTable !== null) {
            $table .= ' (M: ' . $r->mTable . ($r->mPer === null ? '' : ' per ' . $r->mPer) . ')';
        }

        $bounds = [];
        foreach (['query' => $r->query, 'memory' => $r->memory] as $axis => $verdict) {
            if ($verdict->growth === Growth::Unknown && $verdict->lowerBound !== null) {
                $bounds[] = $axis . ' ≥' . $verdict->lowerBound->value;
            }
        }
        $codes = array_values(array_unique(array_map(static fn ($g): string => $g->code->value, $r->gaps)));
        sort($codes, SORT_STRING);

        $methods = $r->methods;
        sort($methods, SORT_STRING);
        $name = in_array('GET', $methods, true) ? $r->uri : implode('|', $methods) . ' ' . $r->uri;

        return [
            $name,
            $query . $notes,
            $memory,
            $table,
            $r->confidence?->value ?? self::NONE,
            $bounds === [] ? self::NONE : implode(', ', $bounds),
            $codes === [] ? self::NONE : implode(', ', $codes),
        ];
    }

    /** O(N), O(N*M), O(N*M)×3 for three levels or more; unknown (≥O(N)) with the lower bound the facts give, unknown (no lower bound) when there is none. */
    private static function label(AxisVerdict $a): string
    {
        if ($a->growth === Growth::Unknown) {
            return 'unknown' . ($a->lowerBound === null ? ' (no lower bound)' : ' (≥' . $a->lowerBound->value . ')');
        }

        return $a->growth->value . ($a->degree >= 3 ? '×' . $a->degree : '');
    }

    private static function missHit(string $miss, string $hit): string
    {
        return $miss === $hit ? $miss : "{$miss} miss / {$hit} hit";
    }

    /** @return list<string> */
    private function summaryLines(ReportSummary $s): array
    {
        $reasons = [];
        foreach ($s->gapReasons as $code => $routes) {
            $reasons[] = "{$code} in {$routes} " . ($routes === 1 ? 'route' : 'routes');
        }

        return [
            sprintf(
                '%s: %d %s analysed, %d not analysed (framework / closure)',
                Name::TOOL_NAME,
                $s->routes,
                $s->routes === 1 ? 'route' : 'routes',
                $s->skipped,
            ),
            sprintf('confidence: high %d, medium %d, %s %d', $s->byConfidence['high'], $s->byConfidence['medium'], self::NONE, $s->byConfidence['none']),
            'gaps: ' . ($reasons === [] ? 'none' : implode(', ', $reasons)),
        ];
    }

    /** Display width: East Asian wide characters (Han, kana, full-width forms) take two columns. */
    private static function width(string $s): int
    {
        return (int) preg_match_all('/./su', $s) + (int) preg_match_all('/[\p{Han}\p{Hiragana}\p{Katakana}\x{FF01}-\x{FF60}]/u', $s);
    }
}
