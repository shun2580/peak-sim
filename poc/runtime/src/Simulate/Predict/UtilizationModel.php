<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Predict;

/**
 * The load of a scenario under one calibration and one reading of the estimate (simulator-spec.md sections 5, 6.1, 6.3).
 *
 * The estimate has four readings, taken over every route at once so that each one is a coherent whole:
 *   point  H1 queries and rows
 *   upper  a marked route takes its upper-side rows (the safe side); an unmarked route keeps its point
 *   low    the smallest values of the estimate (queries_low / rows_low when the route has them, else the point)
 *   high   the largest values (queries_high / rows_high when given, else the larger of the point and the upper side)
 *
 * A route whose cost is "unknown" adds nothing to L here. That is not a claim that it costs 0: it is counted
 * and listed apart (Coverage), and above 20% of the rps the prediction carries `low_coverage`.
 */
final class UtilizationModel
{
    public const POINT = 'point';

    public const UPPER = 'upper';

    public const LOW = 'low';

    public const HIGH = 'high';

    public const READINGS = [self::POINT, self::UPPER, self::LOW, self::HIGH];

    /**
     * The load L of the routes (not yet divided by the cores).
     *
     * @param list<array<string, mixed>> $routes
     */
    public static function load(array $routes, Coefficients $c, string $reading = self::POINT): float
    {
        $sum = 0.0;
        foreach (self::perRoute($routes, $c, $reading) as $l) {
            $sum += $l;
        }

        return $sum;
    }

    /**
     * The two rates a calibration turns into a load: the sum of rps x queries and the sum of rps x rows, over the routes with a known cost.
     *
     * @param list<array<string, mixed>> $routes
     *
     * @return array{queries: float, rows: float}
     */
    public static function rates(array $routes, string $reading = self::POINT): array
    {
        $queries = 0.0;
        $rows = 0.0;
        foreach ($routes as $r) {
            if (($r['cost'] ?? null) !== 'ok') {
                continue;
            }
            [$q, $n] = self::quantities($r, $reading);
            $queries += (float) $r['rps'] * $q;
            $rows += (float) $r['rps'] * $n;
        }

        return ['queries' => $queries, 'rows' => $rows];
    }

    /**
     * L of each route with a known cost, in the order of $routes, keyed by route.
     *
     * @param list<array<string, mixed>> $routes
     *
     * @return array<string, float>
     */
    public static function perRoute(array $routes, Coefficients $c, string $reading = self::POINT): array
    {
        $out = [];
        foreach ($routes as $r) {
            if (($r['cost'] ?? null) !== 'ok') {
                continue;
            }
            [$queries, $rows] = self::quantities($r, $reading);
            $out[(string) $r['route']] = (float) $r['rps'] * ($c->cQ * $queries + $c->cRow * $rows);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $r a route with a known cost
     *
     * @return array{0: float, 1: float} queries and rows under the reading
     */
    public static function quantities(array $r, string $reading): array
    {
        $q = (float) $r['queries'];
        $rows = (float) $r['rows'];
        $upper = isset($r['rows_upper']) ? (float) $r['rows_upper'] : $rows;

        return match ($reading) {
            self::POINT => [$q, $rows],
            self::UPPER => [$q, $upper],
            self::LOW => [
                isset($r['queries_low']) ? (float) $r['queries_low'] : $q,
                isset($r['rows_low']) ? (float) $r['rows_low'] : min($rows, $upper),
            ],
            self::HIGH => [
                isset($r['queries_high']) ? (float) $r['queries_high'] : $q,
                isset($r['rows_high']) ? (float) $r['rows_high'] : max($rows, $upper),
            ],
            default => throw new \InvalidArgumentException('unknown reading of the estimate: ' . $reading),
        };
    }
}
