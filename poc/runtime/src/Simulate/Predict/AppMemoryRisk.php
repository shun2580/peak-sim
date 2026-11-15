<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Predict;

/**
 * The memory side of the mark `app_limit_risk` (simulator-spec.md sections 6.2 and 6.4, D196): a route that may be cut by php's own
 * `memory_limit` before the database is the limit. {@see AppLimitRisk} is the side of the execution time; this is the side of the memory,
 * and the two are judged apart, each with its own block in the output, so that the output says which limit a route was marked for.
 *
 * The limit is an input of the run (`resources.app_memory_limit`, bytes or a size such as 512M); there is no default and the tool cannot read
 * the app's php.ini. With none given nothing here is called, and the output has no trace of it.
 *
 * The model's figure is the route's peak memory at the scenario's table size: the fitted formula of section 6.2 (`a + b x N^k`), which the loaded
 * input carries as `peak_memory` with `peak_memory_status` 'predicted'. {@see self::MEASURED_OVER_PREDICTED} turns the figure into a bound:
 * a route is marked when that factor times its predicted peak memory reaches the limit.
 *
 * The factor is the largest ratio of the measured peak memory of a cold request to the value the committed formula gave for it, over the
 * routes of the second extrapolation test (200,000 rows, both apps) whose measured peak was at least 100 MB;
 * `docs/research/lab-data/diagnosis/app-memory-risk/` gives the records, the way it is worked out, what the rule says on the lab's own
 * records, and what the figure leaves out (the memory of the booted application is in the limit but not in the figure). Like the factor of
 * the execution time it leans to marking too much and not too little: a mark that turns out not to be needed is counted and shown, a route
 * that falls without a mark is the failure.
 *
 * A route is judged whatever the record of its formula says. Section 6.2 shows the count N at which a line is reached only for a route whose
 * formula held within +-30% in the extrapolation tests; the mark does not follow that rule (D196). A route outside +-30% or not measured by any
 * test is judged by the same factor, because the factor is taken over routes inside and outside +-30% alike, and a route that no test could
 * measure (the book page of the lab at 200,000 rows, which ran out of memory in the lab) must not be the one the mark is silent about. A route
 * with no peak-memory estimate at all (`peak_memory_status` is not 'predicted') is not judged and is listed apart with the reason, never counted
 * as having no risk.
 *
 * The mark does not change a utilisation or a limit rps. The limit rps of a scenario counts a marked route as one that runs to the end
 * ({@see AppLimitRisk::LIMIT_RPS_NOTE}, simulator-spec.md R1a).
 */
final class AppMemoryRisk
{
    /** Measured peak memory of a cold request over the formula's value for it, at most over the records of the test (see the class comment). */
    public const MEASURED_OVER_PREDICTED = 1.51;

    public const MARKED = 'marked';

    public const NOT_MARKED = 'not_marked';

    /** The route has no peak-memory estimate, so the mark could not be judged. */
    public const NOT_EVALUATED = 'not_evaluated';

    /**
     * @param list<array<string, mixed>> $routes the routes of one scenario as the loaded input gives them (rps is not used)
     * @param int                        $limit  php's memory limit in bytes, above 0
     *
     * @return array{
     *     limit_bytes: int,
     *     measured_over_predicted: float,
     *     rule: string,
     *     routes: list<array{route: string, status: string, peak_memory_bytes: float|null, bound_bytes: float|null, share_of_limit: float|null, reason: string|null}>,
     *     marked_routes: list<string>,
     *     limit_rps_note: string|null
     * }
     */
    public static function evaluate(array $routes, int $limit): array
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('the app memory limit must be above 0 bytes');
        }
        $rows = [];
        $marked = [];
        foreach ($routes as $r) {
            $name = (string) $r['route'];
            $status = (string) ($r['peak_memory_status'] ?? 'not_given');
            $peak = $r['peak_memory'] ?? null;
            if ($status !== 'predicted' || (!is_int($peak) && !is_float($peak))) {
                $rows[] = ['route' => $name, 'status' => self::NOT_EVALUATED, 'peak_memory_bytes' => null, 'bound_bytes' => null, 'share_of_limit' => null, 'reason' => $status];

                continue;
            }
            $bound = self::MEASURED_OVER_PREDICTED * (float) $peak;
            $isMarked = $bound >= $limit;
            if ($isMarked) {
                $marked[] = $name;
            }
            $rows[] = [
                'route' => $name,
                'status' => $isMarked ? self::MARKED : self::NOT_MARKED,
                'peak_memory_bytes' => (float) $peak,
                'bound_bytes' => $bound,
                'share_of_limit' => $bound / $limit,
                'reason' => null,
            ];
        }

        return [
            'limit_bytes' => $limit,
            'measured_over_predicted' => self::MEASURED_OVER_PREDICTED,
            'rule' => 'marked when measured_over_predicted x the predicted peak memory of one request (section 6.2) >= limit_bytes; whether the formula held within +-30% in the extrapolation tests does not switch the mark off',
            'routes' => $rows,
            'marked_routes' => $marked,
            'limit_rps_note' => $marked === [] ? null : AppLimitRisk::LIMIT_RPS_NOTE,
        ];
    }
}
