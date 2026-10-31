<?php

declare(strict_types=1);

namespace PeakSim\Output;

use PeakSim\Model\Assumption;
use PeakSim\Model\Gap;
use PeakSim\Model\RouteResult;

/**
 * The one fixed order of everything in an output whose own order carries no meaning, so that the same facts
 * give the same bytes however they arrived. Lists whose order is the order of discovery (evidence, cache sites,
 * worst-case candidates) are left as the Judge made them.
 */
final class Canonical
{
    /**
     * @param list<RouteResult> $routes
     * @return list<RouteResult> by uri, then methods, then action
     */
    public static function routes(array $routes): array
    {
        usort($routes, static fn (RouteResult $a, RouteResult $b): int => self::compare(
            [$a->uri, implode(',', $a->methods), $a->action],
            [$b->uri, implode(',', $b->methods), $b->action],
        ));

        return $routes;
    }

    /**
     * @param list<Gap> $gaps
     * @return list<Gap> by place, then code, then message
     */
    public static function gaps(array $gaps): array
    {
        usort($gaps, static fn (Gap $a, Gap $b): int => self::compare(
            [$a->site->file, $a->site->line, $a->code->value, $a->fingerprint(), $a->message],
            [$b->site->file, $b->site->line, $b->code->value, $b->fingerprint(), $b->message],
        ));

        return $gaps;
    }

    /**
     * @param list<Assumption> $assumptions
     * @return list<Assumption> by place, then code, then message
     */
    public static function assumptions(array $assumptions): array
    {
        usort($assumptions, static fn (Assumption $a, Assumption $b): int => self::compare(
            [$a->site->file, $a->site->line, $a->code->value, $a->fingerprint(), $a->message],
            [$b->site->file, $b->site->line, $b->code->value, $b->fingerprint(), $b->message],
        ));

        return $assumptions;
    }

    /**
     * A skipped entry as {route, methods, action, kind}; the route may be given as "route" or "uri".
     *
     * @param array<string, mixed> $entry
     * @return array{route: string, methods: list<string>, action: string, kind: string}
     */
    public static function skippedEntry(array $entry): array
    {
        $methods = array_map('strval', (array) ($entry['methods'] ?? []));
        sort($methods, SORT_STRING);

        return [
            'route' => (string) ($entry['route'] ?? $entry['uri'] ?? ''),
            'methods' => $methods,
            'action' => (string) ($entry['action'] ?? ''),
            'kind' => (string) ($entry['kind'] ?? ''),
        ];
    }

    /**
     * @param list<array<string, mixed>> $skipped
     * @return list<array{route: string, methods: list<string>, action: string, kind: string}>
     */
    public static function skipped(array $skipped): array
    {
        $entries = array_map(self::skippedEntry(...), $skipped);
        usort($entries, static fn (array $a, array $b): int => self::compare(
            [$a['route'], implode(',', $a['methods']), $a['action']],
            [$b['route'], implode(',', $b['methods']), $b['action']],
        ));

        return $entries;
    }

    /**
     * Compares two tuples element by element: integers as integers, everything else byte by byte (never as numbers).
     *
     * @param list<int|string> $a
     * @param list<int|string> $b
     */
    private static function compare(array $a, array $b): int
    {
        foreach ($a as $i => $left) {
            $right = $b[$i];
            $order = is_int($left) && is_int($right) ? $left <=> $right : strcmp((string) $left, (string) $right);
            if ($order !== 0) {
                return $order;
            }
        }

        return 0;
    }
}
