<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Explain;

/**
 * `explain.json`: for every route of the capture, for every statement type, the plan the server gave at the small scale
 * (default state) and at the target scale (every state), the server's own JSON (`raw`) and a summary of it, and the marks
 * `plan_unstable`, `plan_changes_at_scale` and `explain_unavailable` (with the reason). Pure: it only arranges what the steps
 * wrote; it asks nothing of any database.
 */
final class ExplainReport
{
    public const VERSION = 1;

    /**
     * @param array<string, mixed>                                                     $meta       scale, small_scale, capture facts
     * @param list<array<string, mixed>>                                               $statements CaptureFile::read()['statements']
     * @param list<array{role: string, scale: int, database: string, routes: list<string>, results: array<string, list<array{route: string, id: string, plan: ?array<string, mixed>, unavailable: ?string}>>}> $groups
     * @param array<string, string>                                                    $unplanned  route => reason
     * @param array<string, mixed>                                                     $stats      per group, the checks of the statistics
     *
     * @return array<string, mixed>
     */
    public static function assemble(array $meta, array $statements, array $groups, array $unplanned, array $stats): array
    {
        $at = [];       // role => route => id => state => ['summary'=>, 'raw'=>] | ['explain_unavailable'=>]
        foreach ($groups as $g) {
            foreach ($g['results'] as $state => $results) {
                foreach ($results as $r) {
                    $at[$g['role']][$r['route']][$r['id']][$state] = $r['plan'] === null
                        ? ['explain_unavailable' => (string) ($r['unavailable'] ?? 'not_taken')]
                        : ['summary' => PlanSummary::summarize($r['plan']), 'raw' => $r['plan']];
                }
            }
        }

        $routes = [];
        $totals = ['statement_types' => 0, 'plan_unstable' => 0, 'plan_changes_at_scale' => 0, 'explain_unavailable' => 0, 'bindings_redacted' => 0];
        foreach ($statements as $s) {
            $route = $s['route'];
            $small = $at[ExplainPlan::ROLE_SMALL][$route][$s['id']] ?? [];
            $target = $at[ExplainPlan::ROLE_TARGET][$route][$s['id']] ?? [];
            $reason = $unplanned[$route] ?? null;

            $targetSummaries = [];
            $targetWhy = [];
            foreach ($target as $state => $entry) {
                $targetSummaries[$state] = $entry['summary'] ?? null;
                $targetWhy[$state] = $entry['explain_unavailable'] ?? null;
            }
            $smallDefault = $small[PlanMarks::STATE_DEFAULT]['summary'] ?? null;
            $marks = PlanMarks::of($targetSummaries, $targetWhy, $smallDefault);
            if ($target === []) {
                $marks['explain_unavailable'] = $reason ?? 'not_taken_at_the_target_scale';
            }

            $routes[$route]['route'] = $route;
            $routes[$route]['statements'][] = [
                'id' => $s['id'],
                'kind' => $s['kind'],
                'pattern' => $s['pattern'],
                'bindings_redacted' => $s['bindings_redacted'],
                'small_scale' => $small === [] ? ['explain_unavailable' => $reason ?? 'not_taken_at_the_small_scale'] : $small,
                'target_scale' => $target === [] ? ['explain_unavailable' => $marks['explain_unavailable']] : $target,
                'marks' => $marks,
            ];
            ++$totals['statement_types'];
            $totals['plan_unstable'] += $marks['plan_unstable'] ? 1 : 0;
            $totals['plan_changes_at_scale'] += $marks['plan_changes_at_scale'] ? 1 : 0;
            $totals['explain_unavailable'] += $marks['explain_unavailable'] !== null ? 1 : 0;
            $totals['bindings_redacted'] += $s['bindings_redacted'] ? 1 : 0;
        }
        ksort($routes);

        return [
            'explain_version' => self::VERSION,
            'scale' => $meta['scale'] ?? null,
            'small_scale' => $meta['small_scale'] ?? null,
            'states' => StatsStatements::SAMPLE_PAGES,
            'capture' => $meta['capture'] ?? null,
            'totals' => $totals,
            'routes' => array_values($routes),
            'unplanned_routes' => $unplanned,
            'statistics' => $stats,
        ];
    }
}
