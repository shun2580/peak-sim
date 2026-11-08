<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Estimate;

/**
 * The marked routes of the 200,000-row test (simulator-spec.md section 7.1, "200,000 件の測り方"): a route is marked when the EXPLAIN
 * of the database of that size gives `plan_changes_at_scale` or `plan_unstable` to at least one of its statements.
 *
 * The list is made from explain.json alone and by this rule alone. It takes no measured value and has no way to add or drop a route by
 * hand: the same explain.json always gives the same list.
 */
final class MarkedRoutes
{
    public const SCHEMA_VERSION = '1';

    /** @param array<string, mixed>|null $explainRoute one entry of explain.json `routes`, or null when the route has none */
    public static function isMarked(?array $explainRoute): bool
    {
        foreach ((array) ($explainRoute['statements'] ?? []) as $s) {
            $marks = is_array($s) ? (array) ($s['marks'] ?? []) : [];
            if (($marks['plan_changes_at_scale'] ?? false) === true || ($marks['plan_unstable'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $explain the decoded explain.json of the database of the target size
     *
     * @return array<string, mixed>
     */
    public static function list(array $explain): array
    {
        $routes = [];
        $all = [];
        foreach ((array) ($explain['routes'] ?? []) as $r) {
            if (!is_array($r) || !isset($r['route'])) {
                continue;
            }
            $name = (string) $r['route'];
            $all[$name] = true;
            $why = [];
            foreach ((array) ($r['statements'] ?? []) as $s) {
                $marks = is_array($s) ? (array) ($s['marks'] ?? []) : [];
                foreach (['plan_changes_at_scale', 'plan_unstable'] as $mark) {
                    if (($marks[$mark] ?? false) === true) {
                        $why[(string) ($s['id'] ?? '')][] = $mark;
                    }
                }
            }
            if ($why !== []) {
                $statements = [];
                foreach ($why as $id => $marks) {
                    $statements[] = ['id' => (string) $id, 'marks' => $marks];
                }
                $routes[$name] = $statements;
            }
        }
        ksort($routes);
        $out = [];
        foreach ($routes as $name => $statements) {
            $out[] = ['route' => $name, 'statements' => $statements];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'rule' => 'a route with at least one statement marked plan_changes_at_scale or plan_unstable in the EXPLAIN at the target size (simulator-spec.md section 7.1)',
            'app' => $explain['app'] ?? ($explain['capture']['app'] ?? null),
            'at' => (int) ($explain['scale'] ?? 0),
            'routes_in_explain' => count($all),
            'marked_count' => count($out),
            'routes' => $out,
        ];
    }
}
