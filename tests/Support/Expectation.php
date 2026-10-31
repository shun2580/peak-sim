<?php

declare(strict_types=1);

namespace PeakSim\Tests\Support;

/**
 * Compares a mini-app's expect.php with the views the tool produced.
 *
 * expect.php returns:
 *   [
 *     'description' => 'one line',                       // required
 *     'config'      => ['max_depth' => 20],              // optional: max_depth, view_path
 *     'routes'      => ['/orders' => [ ...keys below... ]],   // every analysed route needs an entry
 *   ]
 *
 * Keys of one route (all optional; a missing key is not compared):
 *   query, memory        "O(N)" or ['growth'=>..., 'degree'=>..., 'confidence'=>..., 'lower_bound'=>...] (only the given keys)
 *   confidence           'high' | 'medium' | null
 *   n_table, m_table, m_per   string | null
 *   gaps                 exact set of gap codes, e.g. ['UNRESOLVED_CALL']; [] = no gap at all
 *   gaps_include         codes that must all be among the gaps
 *   gaps_any             at least one of these codes must be among the gaps (the design leaves open which one it is)
 *   assumptions, assumptions_include   the same for assumption codes
 *   events_found         int | '>=1'
 *   truncated            bool
 *   kind                 'controller' | 'closure' | 'framework'
 *   cache                null | ['on_miss'=>['query'=>..,'memory'=>..], 'on_hit'=>[...], 'restores_all_on_hit'=>bool]
 *   worst_case_adopted   bool
 *   coefficients         subset, e.g. ['query_coefficient'=>'1/100']
 *   evidence_include     'file:line' or 'file' strings that must appear in the evidence
 */
final class Expectation
{
    public const ROUTE_KEYS = [
        'query', 'memory', 'confidence', 'n_table', 'm_table', 'm_per', 'gaps', 'gaps_include', 'gaps_any', 'assumptions',
        'assumptions_include', 'events_found', 'truncated', 'kind', 'cache', 'worst_case_adopted', 'coefficients',
        'evidence_include',
    ];

    /**
     * @param array<string, mixed> $expect the array returned by expect.php
     * @param array<string, array<string, mixed>> $views the analysed routes, keyed by uri
     * @return list<string> what differs; empty when the tool agrees with the case
     */
    public static function check(array $expect, array $views): array
    {
        $problems = [];
        $routes = $expect['routes'] ?? null;
        if (!is_array($routes) || $routes === []) {
            return ["expect.php must return a non-empty 'routes' array"];
        }
        foreach ($routes as $uri => $want) {
            if (!isset($views[$uri])) {
                $problems[] = "{$uri}: the tool did not report this route (it reported: " . implode(', ', array_keys($views)) . ')';
                continue;
            }
            foreach (self::checkRoute((string) $uri, $want, $views[$uri]) as $p) {
                $problems[] = $p;
            }
        }
        foreach (array_keys($views) as $uri) {
            if (!array_key_exists($uri, $routes)) {
                $problems[] = "{$uri}: reported by the tool but the case has no expectation for it";
            }
        }

        return $problems;
    }

    /**
     * @param array<string, mixed> $want
     * @param array<string, mixed> $v
     * @return list<string>
     */
    private static function checkRoute(string $uri, array $want, array $v): array
    {
        $p = [];
        foreach (array_diff(array_keys($want), self::ROUTE_KEYS) as $key) {
            $p[] = "{$uri}: unknown expectation key '{$key}' (typo?)";
        }
        foreach (['query', 'memory'] as $axis) {
            if (array_key_exists($axis, $want)) {
                array_push($p, ...self::checkAxis("{$uri} {$axis}", $want[$axis], $v[$axis]));
            }
        }
        foreach (['confidence', 'n_table', 'm_table', 'm_per', 'kind', 'truncated'] as $key) {
            if (array_key_exists($key, $want) && $want[$key] !== $v[$key]) {
                $p[] = "{$uri}: {$key} expected " . self::show($want[$key]) . ', got ' . self::show($v[$key]);
            }
        }
        if (array_key_exists('worst_case_adopted', $want) && $want['worst_case_adopted'] !== $v['worst_case_adopted']) {
            $p[] = "{$uri}: worst_case_adopted expected " . self::show($want['worst_case_adopted']) . ', got ' . self::show($v['worst_case_adopted']);
        }
        if (array_key_exists('events_found', $want)) {
            $ok = $want['events_found'] === '>=1' ? $v['events_found'] >= 1 : $want['events_found'] === $v['events_found'];
            if (!$ok) {
                $p[] = "{$uri}: events_found expected " . self::show($want['events_found']) . ', got ' . $v['events_found'];
            }
        }
        $gapCodes = self::codes($v['gaps']);
        $assumptionCodes = self::codes($v['assumptions']);
        if (array_key_exists('gaps', $want) && self::sorted($want['gaps']) !== $gapCodes) {
            $p[] = "{$uri}: gap codes expected " . self::show(self::sorted($want['gaps'])) . ', got ' . self::show($gapCodes);
        }
        if (array_key_exists('assumptions', $want) && self::sorted($want['assumptions']) !== $assumptionCodes) {
            $p[] = "{$uri}: assumption codes expected " . self::show(self::sorted($want['assumptions'])) . ', got ' . self::show($assumptionCodes);
        }
        foreach ($want['gaps_include'] ?? [] as $code) {
            if (!in_array($code, $gapCodes, true)) {
                $p[] = "{$uri}: gap {$code} expected, got " . self::show($gapCodes);
            }
        }
        if (isset($want['gaps_any']) && array_intersect($want['gaps_any'], $gapCodes) === []) {
            $p[] = "{$uri}: one of the gaps " . self::show($want['gaps_any']) . ' expected, got ' . self::show($gapCodes);
        }
        foreach ($want['assumptions_include'] ?? [] as $code) {
            if (!in_array($code, $assumptionCodes, true)) {
                $p[] = "{$uri}: assumption {$code} expected, got " . self::show($assumptionCodes);
            }
        }
        if (array_key_exists('cache', $want)) {
            array_push($p, ...self::checkCache($uri, $want['cache'], $v['cache']));
        }
        foreach ($want['coefficients'] ?? [] as $name => $value) {
            if (!array_key_exists($name, $v['coefficients']) || $v['coefficients'][$name] != $value) {
                $p[] = "{$uri}: coefficient {$name} expected " . self::show($value) . ', got ' . self::show($v['coefficients'][$name] ?? '(missing)');
            }
        }
        foreach ($want['evidence_include'] ?? [] as $place) {
            if (!self::hasEvidence($v['evidence'], $place)) {
                $p[] = "{$uri}: evidence {$place} expected";
            }
        }

        return $p;
    }

    /**
     * @param string|array<string, mixed> $want
     * @param array<string, mixed> $got
     * @return list<string>
     */
    private static function checkAxis(string $label, string|array $want, array $got): array
    {
        $want = is_string($want) ? ['growth' => $want] : $want;
        $p = [];
        foreach ($want as $key => $value) {
            if (!array_key_exists($key, $got)) {
                $p[] = "{$label}: unknown axis key '{$key}'";
            } elseif ($got[$key] !== $value) {
                $p[] = "{$label}.{$key} expected " . self::show($value) . ', got ' . self::show($got[$key]);
            }
        }

        return $p;
    }

    /**
     * @param array<string, mixed>|null $want
     * @param array<string, mixed>|null $got
     * @return list<string>
     */
    private static function checkCache(string $uri, ?array $want, ?array $got): array
    {
        if ($want === null) {
            return $got === null ? [] : ["{$uri}: no cache view expected, the tool reported one"];
        }
        if ($got === null) {
            return ["{$uri}: a cache view was expected, the tool reported none"];
        }
        $p = [];
        foreach (['on_miss', 'on_hit'] as $side) {
            foreach ($want[$side] ?? [] as $axis => $label) {
                if (($got[$side][$axis]['growth'] ?? null) !== $label) {
                    $p[] = "{$uri}: cache {$side} {$axis} expected {$label}, got " . self::show($got[$side][$axis]['growth'] ?? null);
                }
            }
        }
        if (array_key_exists('restores_all_on_hit', $want) && $want['restores_all_on_hit'] !== $got['restores_all_on_hit']) {
            $p[] = "{$uri}: cache restores_all_on_hit expected " . self::show($want['restores_all_on_hit']);
        }

        return $p;
    }

    /** @param list<array<string, mixed>> $evidence */
    private static function hasEvidence(array $evidence, string $place): bool
    {
        foreach ($evidence as $e) {
            if ($place === $e['file'] . ':' . $e['line'] || $place === $e['file']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<string>
     */
    private static function codes(array $entries): array
    {
        return self::sorted(array_column($entries, 'code'));
    }

    /**
     * @param list<string> $codes
     * @return list<string>
     */
    private static function sorted(array $codes): array
    {
        $codes = array_values(array_unique($codes));
        sort($codes);

        return $codes;
    }

    private static function show(mixed $v): string
    {
        return json_encode($v, JSON_UNESCAPED_SLASHES) ?: var_export($v, true);
    }
}
