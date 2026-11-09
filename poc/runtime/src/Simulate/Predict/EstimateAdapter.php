<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Predict;

use PeakSim\Runtime\Simulate\Load\EstimateFormulas;

/**
 * What the prediction needs from the estimate's formulas, beyond the loaded input: the routes evaluated again at other table counts (for
 * the sensitivity), and the peak-memory formula of every route (for the memory line). It reads through the public API of
 * {@see EstimateFormulas} only, and builds a route the way the first stage does.
 */
final class EstimateAdapter
{
    /**
     * @return callable(array<string, int>, array<string, float>): list<array<string, mixed>>
     */
    public static function evaluator(EstimateFormulas $estimate): callable
    {
        return static function (array $tables, array $rps) use ($estimate): array {
            $names = array_keys(array_filter($rps, static fn (float $v): bool => $v > 0.0));
            sort($names);
            $routes = [];
            foreach ($names as $name) {
                $table = $estimate->tableOf($name);
                $n = $table !== null && isset($tables[$table]) ? $tables[$table] : null;
                $routes[] = array_merge(['route' => $name, 'rps' => $rps[$name], 'table' => $table, 'n' => $n], $estimate->evaluate($name, $n));
            }

            return $routes;
        };
    }

    /**
     * @return array<string, array{table: string|null, a: float, b: float, k: float}|null> route => formula (null: the route has none)
     */
    public static function peakMemoryFormulas(EstimateFormulas $estimate): array
    {
        $out = [];
        foreach ($estimate->routeNames() as $name) {
            $f = $estimate->routes[$name]['peak_memory_formula'] ?? null;
            $out[$name] = is_array($f) && isset($f['a'], $f['b'], $f['k'])
                ? ['table' => $estimate->tableOf($name), 'a' => (float) $f['a'], 'b' => (float) $f['b'], 'k' => (float) $f['k']]
                : null;
        }

        return $out;
    }
}
