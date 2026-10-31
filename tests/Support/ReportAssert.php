<?php

declare(strict_types=1);

namespace PeakSim\Tests\Support;

use PeakSim\Model\AnalysisReport;
use PeakSim\Model\Assumption;
use PeakSim\Model\Axis;
use PeakSim\Model\AxisVerdict;
use PeakSim\Model\CacheView;
use PeakSim\Model\Gap;
use PeakSim\Model\RouteResult;
use PeakSim\Model\Site;

/**
 * Turns the tool's result objects into plain "views" (arrays with fixed keys) and judges them.
 * All the tests read a result through here, so the shape of a result is written down in one place.
 *
 * View of one route (keys in this order):
 *   uri, methods, action, kind,
 *   query / memory: {growth, degree, confidence, lower_bound}   growth: "O(1)" | "O(N)" | "O(N*M)" | "unknown"
 *   n_table, m_table, m_per, n_table_alternatives (list<string>), confidence ("high" | "medium" | null),
 *   cache: null | {on_miss: {query, memory}, on_hit: {query, memory}, restores_all_on_hit, adopted_view}
 *   worst_case_adopted, worst_case: null | list<{file, line, abstract, candidates, chosen}>, coefficients,
 *   gaps: list<{code, file, line, note?, message, affects, loop_depth}>   assumptions: list<{code, affects, message, candidates}>
 *   evidence: list<{role, file, line, axis, note}> (the Judge's frozen form; a bare Site is read as role "event"), events_found, truncated
 */
final class ReportAssert
{
    public const GROWTHS = ['O(1)', 'O(N)', 'O(N*M)', 'unknown'];

    /** @return array<string, mixed> */
    public static function view(RouteResult $r): array
    {
        return [
            'uri' => $r->uri,
            'methods' => $r->methods,
            'action' => $r->action,
            'kind' => $r->kind,
            'query' => self::axis($r->query),
            'memory' => self::axis($r->memory),
            'n_table' => $r->nTable,
            'm_table' => $r->mTable,
            'm_per' => $r->mPer,
            'n_table_alternatives' => $r->nTableAlternatives,
            'confidence' => $r->confidence?->value,
            'cache' => $r->cache === null ? null : self::cache($r->cache),
            'worst_case_adopted' => $r->worstCase !== null,
            'worst_case' => $r->worstCase === null ? null : array_map(self::worstCase(...), $r->worstCase),
            'coefficients' => $r->coefficients,
            'gaps' => array_map(self::gap(...), $r->gaps),
            'assumptions' => array_map(self::assumption(...), $r->assumptions),
            'evidence' => array_map(self::evidence(...), $r->evidence),
            'events_found' => $r->eventsFound,
            'truncated' => $r->truncated,
        ];
    }

    /**
     * @return array<string, array<string, mixed>> views keyed by uri
     */
    public static function views(AnalysisReport $report): array
    {
        $views = [];
        foreach ($report->routes as $route) {
            $views[$route->uri] = self::view($route);
        }

        return $views;
    }

    /** The JSON a report is compared by in the determinism and input-mode tests. */
    public static function json(AnalysisReport $report): string
    {
        return self::encode(['routes' => array_values(self::views($report)), 'skipped' => $report->skipped, 'scope' => $report->scope]);
    }

    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /** O(1)=0, O(N)=1, O(N*M)=2; unknown counts as the worst there is. */
    public static function rank(string $growth): int
    {
        return match ($growth) {
            'O(1)' => 0,
            'O(N)' => 1,
            'O(N*M)' => 2,
            'unknown' => PHP_INT_MAX,
            default => throw new \InvalidArgumentException("not a growth label: {$growth}"),
        };
    }

    /** Compares an actual label with an expected one: "match", "over" (heavier than expected) or "missed" (lighter). */
    public static function compare(string $expected, string $actual): string
    {
        $e = self::rank($expected);
        $a = self::rank($actual);

        return $a === $e ? 'match' : ($a > $e ? 'over' : 'missed');
    }

    /**
     * Checks what must hold of any verdict, whatever the app (design 1.2 / 1.8). Returns the violations.
     *
     * @param array<string, mixed> $v a route view
     * @return list<string>
     */
    public static function invariants(array $v): array
    {
        $uri = $v['uri'];
        $out = [];
        $gaps = $v['gaps'];
        $codes = array_column($gaps, 'code');
        $degreeLabel = [0 => 'O(1)', 1 => 'O(N)'];

        foreach (['query', 'memory'] as $axis) {
            $a = $v[$axis];
            if (!in_array($a['growth'], self::GROWTHS, true)) {
                $out[] = "{$uri}: {$axis}.growth is not a known label ({$a['growth']})";
                continue;
            }
            if ($a['growth'] === 'unknown') {
                if ($a['confidence'] !== null) {
                    $out[] = "{$uri}: {$axis} is unknown but carries a confidence";
                }
                $affecting = array_filter($gaps, static fn (array $g): bool => in_array($axis, $g['affects'], true));
                if ($affecting === []) {
                    $out[] = "{$uri}: {$axis} is unknown with no gap that affects it (I2: unknown must be explained)";
                }
                if ($a['lower_bound'] === 'unknown') {
                    $out[] = "{$uri}: {$axis}.lower_bound must be a finite growth, not unknown";
                }
            } else {
                if (!in_array($a['confidence'], ['high', 'medium'], true)) {
                    $out[] = "{$uri}: {$axis} is {$a['growth']} but has no confidence";
                }
                $expectedLabel = $degreeLabel[$a['degree']] ?? 'O(N*M)';
                if ($a['degree'] < 0 || $a['growth'] !== $expectedLabel) {
                    $out[] = "{$uri}: {$axis} degree {$a['degree']} does not fit label {$a['growth']}";
                }
            }
        }

        // Q3 (D21): a route has a confidence only when both axes are known; then it is the lower of the two.
        $axisConfidences = [$v['query']['confidence'], $v['memory']['confidence']];
        $anyUnknown = $v['query']['growth'] === 'unknown' || $v['memory']['growth'] === 'unknown';
        $want = $anyUnknown || in_array(null, $axisConfidences, true) ? null : (in_array('medium', $axisConfidences, true) ? 'medium' : 'high');
        if ($v['confidence'] !== $want) {
            $out[] = '' . $uri . ': route confidence must be null when either axis is unknown, otherwise the lower of the two axes (expected '
                . var_export($want, true) . ', got ' . var_export($v['confidence'], true) . ')';
        }

        if ($v['events_found'] === 0 && $gaps === [] && ($v['query']['growth'] !== 'O(1)' || $v['memory']['growth'] !== 'O(1)')) {
            $out[] = "{$uri}: nothing was found and nothing was missed, so both axes must be O(1)";
        }
        if ($v['truncated'] && !in_array('DEPTH_LIMIT', $codes, true)) {
            $out[] = "{$uri}: truncated=true needs a DEPTH_LIMIT gap";
        }
        if ($v['worst_case_adopted'] && !in_array('WORST_CASE_OF_CANDIDATES', array_column($v['assumptions'], 'code'), true)) {
            $out[] = "{$uri}: worst_case_adopted needs a WORST_CASE_OF_CANDIDATES assumption";
        }
        foreach (array_merge($gaps, $v['evidence']) as $place) {
            if (str_starts_with((string) $place['file'], '/') || str_contains((string) $place['file'], '/home/')) {
                $out[] = "{$uri}: an absolute path leaked into the result ({$place['file']})";
            }
        }

        return $out;
    }

    /**
     * An evidence item as the Judge makes it: ['role', 'site' => Site, 'axis', 'note']. A bare Site (the older form
     * of the contract's docblock) is accepted too, so a result in either form can be read.
     *
     * @return array{role: string, file: string, line: int, axis: ?string, note: string}
     */
    private static function evidence(Site|array $item): array
    {
        if ($item instanceof Site) {
            return ['role' => 'event', 'file' => $item->file, 'line' => $item->line, 'axis' => null, 'note' => $item->note];
        }
        $site = $item['site'];

        return ['role' => (string) ($item['role'] ?? ''), 'file' => $site->file, 'line' => $site->line, 'axis' => $item['axis'] ?? null, 'note' => (string) ($item['note'] ?? '')];
    }

    /**
     * A worst-case entry as the Judge makes it: ['site' => Site, 'abstract', 'candidates', 'chosen'].
     *
     * @param array<string, mixed> $w
     * @return array{file: ?string, line: ?int, abstract: ?string, candidates: list<string>, chosen: ?string}
     */
    private static function worstCase(array $w): array
    {
        $site = $w['site'] ?? null;

        return [
            'file' => $site instanceof Site ? $site->file : null,
            'line' => $site instanceof Site ? $site->line : null,
            'abstract' => $w['abstract'] ?? null,
            'candidates' => array_values($w['candidates'] ?? []),
            'chosen' => $w['chosen'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private static function axis(AxisVerdict $a): array
    {
        return [
            'growth' => $a->growth->value,
            'degree' => $a->degree,
            'confidence' => $a->confidence?->value,
            'lower_bound' => $a->lowerBound?->value,
        ];
    }

    /** @return array<string, mixed> */
    private static function cache(CacheView $c): array
    {
        return [
            'on_miss' => ['query' => self::axis($c->onMiss['query']), 'memory' => self::axis($c->onMiss['memory'])],
            'on_hit' => ['query' => self::axis($c->onHit['query']), 'memory' => self::axis($c->onHit['memory'])],
            'restores_all_on_hit' => $c->restoresAllOnHit,
            'adopted_view' => $c->adoptedView,
        ];
    }

    /** @return array<string, mixed> */
    private static function gap(Gap $g): array
    {
        return [
            'code' => $g->code->value,
            'file' => $g->site->file,
            'line' => $g->site->line,
            'note' => $g->site->note,
            'message' => $g->message,
            'affects' => array_map(static fn (Axis $a): string => $a->value, $g->affects),
            'loop_depth' => $g->loopDepth,
        ];
    }

    /** @return array<string, mixed> */
    private static function assumption(Assumption $a): array
    {
        return [
            'code' => $a->code->value,
            'file' => $a->site->file,
            'line' => $a->site->line,
            'message' => $a->message,
            'affects' => array_map(static fn (Axis $x): string => $x->value, $a->affects),
            'candidates' => $a->candidates,
        ];
    }
}
