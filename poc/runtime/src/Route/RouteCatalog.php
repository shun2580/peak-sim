<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Route;

/**
 * The map of routes to hit: the static analysis output says which routes exist and what it concluded; the runtime
 * route table (rows read from the booted app) says how to reach them.
 *
 * A runtime row is: uri (no leading slash, as Laravel prints it), methods, middleware, action, and optionally
 * wheres (parameter => regex body) and parameters (name => {model, field, type, scope, optional}).
 * Only controller routes are catalogued, the same as the static analysis; the rest is counted and left alone.
 */
final class RouteCatalog
{
    /**
     * @param list<RouteSpec> $specs
     */
    private function __construct(private readonly array $specs, private readonly int $skipped)
    {
    }

    /**
     * @param array<string, mixed> $analysis the static analysis output (`routes`, optionally `skipped`)
     * @param list<array<string, mixed>> $runtimeRows
     */
    public static function build(array $analysis, array $runtimeRows): self
    {
        $specs = [];
        $skipped = count($analysis['skipped'] ?? []);

        foreach ($analysis['routes'] ?? [] as $route) {
            if (($route['kind'] ?? 'controller') !== 'controller') {
                ++$skipped;

                continue;
            }
            $methods = array_values(array_filter(array_map('strtoupper', $route['methods'] ?? []), static fn (string $m): bool => $m !== 'HEAD'));
            if ($methods === []) {
                $methods = ['GET'];
            }
            $method = in_array('GET', $methods, true) ? 'GET' : $methods[0];
            $runtime = self::findRuntime((string) $route['route'], $methods, $runtimeRows);

            $specs[] = new RouteSpec(
                uri: (string) $route['route'],
                method: $method,
                methods: $methods,
                middleware: array_values(array_map('strval', $runtime['middleware'] ?? [])),
                parameters: self::parameters((string) $route['route'], $runtime),
                action: isset($route['action']) ? (string) $route['action'] : null,
                static: [
                    'query' => self::axis($route['query'] ?? []),
                    'memory' => self::axis($route['memory'] ?? []),
                    'n_table' => $route['n_table'] ?? null,
                    'm_table' => $route['m_table'] ?? null,
                    'm_per' => $route['m_per'] ?? null,
                    'n_table_alternatives' => array_values($route['n_table_alternatives'] ?? []),
                ],
                inRuntime: $runtime !== null,
            );
        }

        return new self($specs, $skipped);
    }

    /** @return list<RouteSpec> */
    public function specs(): array
    {
        return $this->specs;
    }

    /** @return list<RouteSpec> routes the static analysis lists that the booted app does not have */
    public function notInRuntime(): array
    {
        return array_values(array_filter($this->specs, static fn (RouteSpec $s): bool => ! $s->inRuntime));
    }

    public function skippedCount(): int
    {
        return $this->skipped;
    }

    /**
     * @param list<string> $methods
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>|null
     */
    private static function findRuntime(string $uri, array $methods, array $rows): ?array
    {
        $key = ltrim($uri, '/');
        foreach ($rows as $row) {
            if (ltrim((string) ($row['uri'] ?? ''), '/') !== $key) {
                continue;
            }
            $rowMethods = array_map('strtoupper', $row['methods'] ?? []);
            if (array_intersect($methods, $rowMethods) !== []) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $runtime
     *
     * @return array<string, array{optional: bool, where: ?string, model: ?string, field: ?string, type: ?string, scope: ?array{parent: string, fk: string}}>
     */
    private static function parameters(string $uri, ?array $runtime): array
    {
        $out = [];
        preg_match_all('/\{(\w+)(?::(\w+))?(\?)?\}/', $uri, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $name = $m[1];
            $detail = $runtime['parameters'][$name] ?? [];
            $out[$name] = [
                'optional' => ($m[3] ?? '') === '?',
                'where' => isset($runtime['wheres'][$name]) ? (string) $runtime['wheres'][$name] : null,
                'model' => $detail['model'] ?? null,
                'field' => ($m[2] ?? '') !== '' ? $m[2] : ($detail['field'] ?? null),
                'type' => $detail['type'] ?? null,
                'scope' => $detail['scope'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $axis
     *
     * @return array{growth: ?string, lower_bound: ?string, confidence: ?string}
     */
    private static function axis(array $axis): array
    {
        return [
            'growth' => $axis['growth'] ?? null,
            'lower_bound' => $axis['lower_bound'] ?? null,
            'confidence' => $axis['confidence'] ?? null,
        ];
    }
}
