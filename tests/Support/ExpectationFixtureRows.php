<?php

declare(strict_types=1);

namespace PeakSim\Tests\Support;

/**
 * fixtures/expected.json read at run time (nothing is copied into a test), and the rule for comparing one of
 * its rows with the tool's view of the route (spec S-32: query, memory and the N(M) tables; a null stays unchecked).
 */
final class ExpectationFixtureRows
{
    /** @return array<string, array<string, mixed>> rows keyed by route, in file order */
    public static function rows(): array
    {
        $json = json_decode(MiniAppWorkspace::read(dirname(MiniAppWorkspace::expectedJson()), 'expected.json'), true, 512, JSON_THROW_ON_ERROR);
        $rows = [];
        foreach ($json['routes'] as $row) {
            $rows[$row['route']] = $row;
        }

        return $rows;
    }

    /**
     * Compares one row with the view of the route.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $view
     * @return array{query: string, memory: string, n_table: string, problems: list<string>}
     *         per axis: "match", "over" (heavier than expected), "missed" (lighter than expected), "mismatch" (tables), "-" (not compared)
     */
    public static function compare(array $row, array $view): array
    {
        $uri = $row['route'];
        $problems = [];
        $cached = $row['cached'] === true;
        $result = ['query' => '-', 'memory' => '-', 'n_table' => '-', 'problems' => []];

        foreach (['query', 'memory'] as $axis) {
            $expected = $row[$axis];
            if ($expected === null) {
                continue;
            }
            $result[$axis] = ReportAssert::compare($expected, $view[$axis]['growth']);
            if ($result[$axis] !== 'match') {
                $problems[] = "{$uri}: {$axis} expected {$expected}, got {$view[$axis]['growth']} ({$result[$axis]})";
            }
        }

        $tables = ['n_table' => 'n_table', 'm_table' => 'm_table', 'm_per' => 'm_per'];
        $tableOk = true;
        foreach ($tables as $key => $viewKey) {
            if ($row[$key] !== null && $row[$key] !== $view[$viewKey]) {
                $tableOk = false;
                $problems[] = "{$uri}: {$key} expected {$row[$key]}, got " . var_export($view[$viewKey], true);
            }
        }
        if ($row['query'] === 'unknown') {
            // "could not follow", not "nothing there": no table, no event, at least one gap.
            if ($view['n_table'] !== null) {
                $tableOk = false;
                $problems[] = "{$uri}: n_table must be null for an unknown route, got " . var_export($view['n_table'], true);
            }
            if ($view['events_found'] !== 0 || count($view['gaps']) < 1) {
                $problems[] = "{$uri}: an unknown route must have events_found=0 and at least one gap";
            }
        }
        $result['n_table'] = $row['n_table'] === null && $row['query'] !== 'unknown' ? '-' : ($tableOk ? 'match' : 'mismatch');

        if ($cached) {
            $cache = $view['cache'];
            if ($cache === null) {
                $problems[] = "{$uri}: a cache view (miss / hit) is required";
            } else {
                foreach (['on_miss' => 'query_on_miss', 'on_hit' => 'query_on_hit'] as $side => $key) {
                    if ($cache[$side]['query']['growth'] !== $row[$key]) {
                        $problems[] = "{$uri}: cache {$side} query expected {$row[$key]}, got {$cache[$side]['query']['growth']}";
                    }
                }
                if ($cache['restores_all_on_hit'] !== $row['memory_restores_all_on_hit']) {
                    $problems[] = "{$uri}: cache restores_all_on_hit expected " . var_export($row['memory_restores_all_on_hit'], true);
                }
            }
        }
        if ($row['worst_case_adopted'] === true) {
            if ($view['worst_case_adopted'] !== true) {
                $problems[] = "{$uri}: worst_case_adopted must be true";
            }
            if ($view['confidence'] !== 'medium') {
                $problems[] = "{$uri}: a worst-case verdict must have confidence medium, got " . var_export($view['confidence'], true);
            }
        }
        $result['problems'] = $problems;

        return $result;
    }

    /**
     * The expectation of a row as an Expectation-style 'want' (used by the stand-in analyzer).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function toWant(array $row): array
    {
        $unknown = $row['query'] === 'unknown';
        $want = [
            'query' => $row['query'] ?? $row['query_on_miss'],
            'memory' => $row['memory'],
            'n_table' => $row['n_table'],
            'm_table' => $row['m_table'],
            'm_per' => $row['m_per'],
        ];
        if ($unknown) {
            $want['gaps'] = ['DYNAMIC_METHOD_NAME'];
            $want['events_found'] = 0;
        } else {
            $want['gaps'] = [];
            $want['events_found'] = '>=1';
        }
        if ($row['cached'] === true) {
            $want['cache'] = [
                'on_miss' => ['query' => $row['query_on_miss'], 'memory' => $row['memory']],
                'on_hit' => ['query' => $row['query_on_hit'], 'memory' => $row['memory']],
                'restores_all_on_hit' => $row['memory_restores_all_on_hit'],
            ];
        }
        if ($row['worst_case_adopted'] === true) {
            $want['worst_case_adopted'] = true;
            $want['assumptions'] = ['WORST_CASE_OF_CANDIDATES'];
            $want['confidence'] = 'medium';
        }

        return $want;
    }
}
