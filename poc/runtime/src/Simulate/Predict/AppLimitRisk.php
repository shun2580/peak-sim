<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Predict;

/**
 * The mark `app_limit_risk` (simulator-spec.md section 6.4): a route that may be cut by the app's own execution limit (php's
 * max_execution_time) before the database is the limit.
 *
 * The limit is an input of the run (`resources.app_cpu_time_limit`, in seconds); there is no default. php counts the limit in the CPU time
 * of its own process, not in clock time, and the model has only the database time of one request (c_q x queries + c_row x rows, the
 * calibration's conversion). {@see self::PHP_CPU_OVER_DB} turns the one into a bound of the other: a route is marked when that factor times
 * the converted database time of one request (the larger of the point and the safe side) reaches the limit.
 *
 * The factor is the largest ratio of php CPU time to measured database CPU time in the lab's per-request records at 10,000, 30,000 and
 * 100,000 rows (the records whose database CPU reading was not withheld); `docs/research/lab-data/diagnosis/app-limit-risk/` gives the
 * records, the way it is worked out and what the rule says on them. It leans to marking too much, not too little: a mark that turns out
 * not to be needed is counted and shown, a route that is cut without a mark is the failure.
 *
 * The mark does not change a utilisation or a limit rps. The limit rps of a scenario counts a marked route as one that runs to the end
 * ({@see self::LIMIT_RPS_NOTE}, simulator-spec.md R1a).
 *
 * Nothing here reads the estimate's rules or the calibration's formula: it takes the converted time of one request per route as a number.
 */
final class AppLimitRisk
{
    /** php CPU time of one request over its measured database CPU time, at most over the records of the lab (see the class comment). */
    public const PHP_CPU_OVER_DB = 9.11;

    public const MARKED = 'marked';

    public const NOT_MARKED = 'not_marked';

    /** The route has no cost (the model has no database time for it), so the mark could not be judged. */
    public const NOT_EVALUATED = 'not_evaluated';

    public const LIMIT_RPS_NOTE = 'the limit rps counts the marked routes as requests that run to the end; a marked route may be cut by the app\'s limit first';

    /**
     * @param list<array<string, mixed>> $routes the routes of one scenario as the loaded input gives them (rps is not used)
     * @param float                      $limit  the app's execution limit in seconds, above 0
     *
     * @return array{
     *     limit_s: float,
     *     php_cpu_over_db: float,
     *     rule: string,
     *     routes: list<array{route: string, status: string, db_time_s: array{point: float, upper: float}|null, php_cpu_bound_s: float|null, share_of_limit: float|null}>,
     *     marked_routes: list<string>,
     *     limit_rps_note: string|null
     * }
     */
    public static function evaluate(array $routes, Coefficients $c, float $limit): array
    {
        if (!($limit > 0.0)) {
            throw new \InvalidArgumentException('the app limit must be above 0 seconds');
        }
        $rows = [];
        $marked = [];
        foreach ($routes as $r) {
            $name = (string) $r['route'];
            if (($r['cost'] ?? null) !== 'ok') {
                $rows[] = ['route' => $name, 'status' => self::NOT_EVALUATED, 'db_time_s' => null, 'php_cpu_bound_s' => null, 'share_of_limit' => null];

                continue;
            }
            $point = self::seconds($r, $c, UtilizationModel::POINT);
            $upper = self::seconds($r, $c, UtilizationModel::UPPER);
            $bound = self::PHP_CPU_OVER_DB * max($point, $upper);
            $isMarked = $bound >= $limit;
            if ($isMarked) {
                $marked[] = $name;
            }
            $rows[] = [
                'route' => $name,
                'status' => $isMarked ? self::MARKED : self::NOT_MARKED,
                'db_time_s' => ['point' => $point, 'upper' => $upper],
                'php_cpu_bound_s' => $bound,
                'share_of_limit' => $bound / $limit,
            ];
        }

        return [
            'limit_s' => $limit,
            'php_cpu_over_db' => self::PHP_CPU_OVER_DB,
            'rule' => 'marked when php_cpu_over_db x the database time the model gives for one request (larger of point and upper side) >= limit_s',
            'routes' => $rows,
            'marked_routes' => $marked,
            'limit_rps_note' => $marked === [] ? null : self::LIMIT_RPS_NOTE,
        ];
    }

    /** @param array<string, mixed> $route a route with a known cost */
    private static function seconds(array $route, Coefficients $c, string $reading): float
    {
        [$queries, $rows] = UtilizationModel::quantities($route, $reading);

        return $c->cQ * $queries + $c->cRow * $rows;
    }
}
