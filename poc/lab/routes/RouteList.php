<?php

declare(strict_types=1);

namespace PeakSim\Lab\Routes;

/**
 * The route list of the lab backtest (simulator-spec.md section 7.2, "ルートの選び方").
 *
 * Takes the GET routes whose cost the estimate (section 4) could work out, orders them by the degree of the static lower bound, largest
 * first, and by the string "METHOD URI" (byte order) when the degree is the same, and keeps the first eight. The first four of the
 * order are the first half and the last four are the second half; the scenarios cal-2 and cal-3 mix the routes by these halves.
 *
 * Made from the two input files by this rule alone: nothing is measured, nothing is added or dropped by hand, and the same inputs give
 * the same output, byte for byte (no time and no path of this machine goes in).
 */
final class RouteList
{
    public const SCHEMA_VERSION = '1';

    public const TOP = 8;

    /** A route whose static analysis found no lower bound ranks below every route that has one. */
    public const NO_BOUND_DEGREE = 0;

    /**
     * The degree of a static lower bound such as `O(N)` or `O(N*M)`: the sum of the exponents of its factors. `O(1)` and no bound are 0.
     * A form this does not know is refused, never read as 0, so that a new form cannot slip into the order unseen.
     */
    public static function degree(?string $bound): int
    {
        if ($bound === null || $bound === '') {
            return self::NO_BOUND_DEGREE;
        }
        if (preg_match('~^O\((.+)\)$~', $bound, $m) !== 1) {
            throw new \InvalidArgumentException('unknown static lower bound: ' . $bound);
        }
        if ($m[1] === '1') {
            return 0;
        }
        $degree = 0;
        foreach (explode('*', $m[1]) as $factor) {
            if (preg_match('~^[A-Z]+(?:\^([1-9][0-9]*))?$~', $factor, $f) !== 1) {
                throw new \InvalidArgumentException('unknown static lower bound: ' . $bound);
            }
            $degree += isset($f[1]) ? (int) $f[1] : 1;
        }

        return $degree;
    }

    /** True for the routes of the form "GET /uri". */
    public static function isGet(string $route): bool
    {
        return str_starts_with($route, 'GET ');
    }

    /**
     * @param array<string, mixed> $estimate the decoded estimate.json (section 4)
     * @param array<string, mixed> $fits     the decoded fits.json (carries `static_lower_bound` of each route)
     * @param array<string, array{path: string, sha256: string}> $inputs the records of the input files, see inputRecord()
     *
     * @return array<string, mixed>
     */
    public static function build(array $estimate, array $fits, array $inputs = []): array
    {
        $bounds = [];
        foreach ((array) ($fits['routes'] ?? []) as $r) {
            if (is_array($r) && isset($r['route'])) {
                $bound = $r['static_lower_bound'] ?? null;
                $bounds[(string) $r['route']] = is_string($bound) ? $bound : null;
            }
        }

        $estimated = [];
        $getRoutes = 0;
        $withoutEstimate = [];
        foreach ((array) ($estimate['routes'] ?? []) as $r) {
            if (!is_array($r) || !isset($r['route'])) {
                continue;
            }
            $route = (string) $r['route'];
            if (!self::isGet($route)) {
                continue;
            }
            $getRoutes++;
            if (($r['status'] ?? null) !== 'captured' || ($r['complete'] ?? false) !== true) {
                $withoutEstimate[] = $route;
                continue;
            }
            $bound = $bounds[$route] ?? null;
            $estimated[$route] = ['route' => $route, 'static_lower_bound' => $bound, 'degree' => self::degree($bound)];
        }

        $candidates = array_values($estimated);
        usort($candidates, static function (array $a, array $b): int {
            // largest degree first; the same degree: byte order of "METHOD URI"
            return $b['degree'] <=> $a['degree'] ?: strcmp($a['route'], $b['route']);
        });

        $ranked = [];
        foreach ($candidates as $i => $c) {
            $rank = $i + 1;
            $selected = $rank <= self::TOP;
            $ranked[] = $c + [
                'rank' => $rank,
                'selected' => $selected,
                'half' => $selected ? ($rank <= intdiv(self::TOP, 2) ? 'first' : 'second') : null,
            ];
        }
        sort($withoutEstimate, SORT_STRING);

        $selected = array_values(array_filter($ranked, static fn (array $c): bool => $c['selected']));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'rule' => 'GET routes with a cost estimate (section 4: status captured and complete), ordered by the degree of the static lower bound, '
                . 'largest first, then by the string "METHOD URI" in byte order; the first ' . self::TOP . ' are taken; '
                . 'ranks 1-4 are the first half and ranks 5-8 the second half (simulator-spec.md section 7.2)',
            'degree_of' => 'sum of the exponents of the factors of O(...); O(1) and no bound are 0 (O(N) = 1, O(N*M) = 2)',
            'inputs' => $inputs,
            'counts' => [
                'get_routes_in_estimate' => $getRoutes,
                'get_routes_with_estimate' => count($ranked),
                'get_routes_without_estimate' => count($withoutEstimate),
                'selected' => count($selected),
            ],
            'selected' => $selected,
            'candidates' => $ranked,
            'get_routes_without_estimate' => $withoutEstimate,
        ];
    }

    /** @return array{path: string, sha256: string} */
    public static function inputRecord(string $pathAsGiven, string $contents): array
    {
        return ['path' => $pathAsGiven, 'sha256' => hash('sha256', $contents)];
    }
}
