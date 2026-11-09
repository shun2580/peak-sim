<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Predict;

/**
 * How much of the scenario the estimate covers (simulator-spec.md sections 3.2 and 6.4): routes and the share of rps, by where the
 * point comes from, by mark, and the routes with no cost. `low_coverage` is set when the routes with no cost carry more than 20%
 * of the rps. Exactly 20% is not over.
 */
final class Coverage
{
    public const LOW_COVERAGE_OVER = 0.2;

    /** The sources a value has (simulator-spec.md 4.6). `unknown` is a route with no cost. */
    public const SOURCES = ['static', 'explain', 'fit', 'unknown'];

    /**
     * @param list<array<string, mixed>> $routes the routes with rps above 0
     *
     * @return array<string, mixed>
     */
    public static function of(array $routes): array
    {
        $totalRps = 0.0;
        foreach ($routes as $r) {
            $totalRps += (float) $r['rps'];
        }
        $bySource = array_fill_keys(self::SOURCES, ['routes' => 0, 'rps' => 0.0]);
        $marked = ['routes' => 0, 'rps' => 0.0];
        $unmarked = ['routes' => 0, 'rps' => 0.0];
        $unknown = [];
        $unknownRps = 0.0;
        foreach ($routes as $r) {
            $rps = (float) $r['rps'];
            $isUnknown = ($r['cost'] ?? null) !== 'ok';
            $source = $isUnknown ? 'unknown' : (string) ($r['sources']['rows'] ?? 'fit');
            if (!isset($bySource[$source])) {
                $source = 'fit';
            }
            $bySource[$source]['routes']++;
            $bySource[$source]['rps'] += $rps;
            if ($isUnknown) {
                $unknown[] = ['route' => (string) $r['route'], 'rps' => $rps, 'reason' => (string) ($r['unknown_reason'] ?? 'unknown')];
                $unknownRps += $rps;
                continue;
            }
            if (($r['marked'] ?? false) === true) {
                $marked['routes']++;
                $marked['rps'] += $rps;
            } else {
                $unmarked['routes']++;
                $unmarked['rps'] += $rps;
            }
        }
        usort($unknown, static fn (array $a, array $b): int => strcmp($a['route'], $b['route']));
        $share = static fn (float $x): float => $totalRps > 0.0 ? $x / $totalRps : 0.0;
        foreach ($bySource as &$s) {
            $s['rps_share'] = $share($s['rps']);
        }
        unset($s);
        $marked['rps_share'] = $share($marked['rps']);
        $unmarked['rps_share'] = $share($unmarked['rps']);

        return [
            'routes' => count($routes),
            'rps_total' => $totalRps,
            'by_source' => $bySource,
            'marked' => $marked,
            'unmarked' => $unmarked,
            'unknown_cost_routes' => $unknown,
            'unknown_cost_rps_share' => $share($unknownRps),
            'low_coverage' => $share($unknownRps) > self::LOW_COVERAGE_OVER * (1.0 + LimitSearch::MARGIN),
        ];
    }
}
