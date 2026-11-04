<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Judge;

/**
 * Puts a size N into the fitted cost formulas (`y = a + b * N^k`, from `bin/fit`) and records the values.
 *
 * A quantity whose `fit_status` is not `ok` gets no value, only the status and the reason it had, so a missing
 * prediction is never mistaken for a prediction of zero. Deterministic: the same fits and the same N give the same
 * bytes, with the routes in the order of their names.
 */
final class Predictor
{
    public const SCHEMA_VERSION = '0.1';

    public const QUANTITIES = ['queries', 'rows_examined', 'peak_memory'];

    public const PREDICTED = 'predicted';

    public const NOT_PREDICTED = 'not_predicted';

    /**
     * @param array<string, mixed> $fits the decoded fits.json
     *
     * @return array<string, mixed>
     */
    public static function predict(array $fits, int $at): array
    {
        if ($at < 1) {
            throw new \InvalidArgumentException('the size to predict at must be a positive integer');
        }
        $routes = [];
        foreach ((array) ($fits['routes'] ?? []) as $route) {
            $quantities = [];
            foreach (self::QUANTITIES as $name) {
                if (isset($route['quantities'][$name])) {
                    $quantities[$name] = self::one((array) $route['quantities'][$name], $at);
                }
            }
            $routes[] = [
                'route' => (string) $route['route'],
                'axis' => (string) ($route['axis'] ?? 'N'),
                'table' => $route['table'] ?? null,
                'static_lower_bound' => $route['static_lower_bound'] ?? null,
                'quantities' => $quantities,
            ];
        }
        usort($routes, static fn (array $x, array $y): int => strcmp($x['route'], $y['route']));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'source' => $fits['source'] ?? null,
            'at' => $at,
            'routes' => $routes,
        ];
    }

    /**
     * @param array<string, mixed> $fit
     *
     * @return array<string, mixed>
     */
    private static function one(array $fit, int $at): array
    {
        $status = (string) ($fit['fit_status'] ?? 'missing');
        $usable = isset($fit['a'], $fit['b'], $fit['k']) && is_numeric($fit['a']) && is_numeric($fit['b']) && is_numeric($fit['k']);
        if ($status !== 'ok' || !$usable) {
            return [
                'status' => self::NOT_PREDICTED,
                'fit_status' => $status,
                'reason' => (string) ($fit['reason'] ?? ($status === 'ok' ? 'the fit has no a, b and k' : $status)),
            ];
        }

        return [
            'status' => self::PREDICTED,
            'fit_status' => 'ok',
            'value' => (float) $fit['a'] + (float) $fit['b'] * ((float) $at) ** (float) $fit['k'],
            'a' => (float) $fit['a'],
            'b' => (float) $fit['b'],
            'k' => (float) $fit['k'],
        ];
    }
}
