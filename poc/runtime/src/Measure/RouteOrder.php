<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

/**
 * The order in which the routes that share one database are measured, for an app whose profile names routes that rewrite a table other
 * routes read (`state_changing` in the profile). Each request is rolled back, so the rows come back, but the optimizer's statistics of the
 * rewritten table do not, and a route measured after it reads the table in another state. Those routes are measured after every other
 * route of the database, in the order the profile lists them (so a route that changes the statistics a later one reads comes later).
 * Every other route keeps the order it had. The same rule orders the driver's measurement (`Driver`) and the repeat (`TrialPlan`).
 */
final class RouteOrder
{
    /**
     * @template T
     *
     * @param list<T>              $items the routes of one database, in their order
     * @param \Closure(T): string  $keyOf the "METHOD uri" of an item
     * @param list<string>         $last  the "METHOD uri" of the routes that go last, in the order they go
     *
     * @return list<T>
     */
    public static function lastRoutesLast(array $items, \Closure $keyOf, array $last): array
    {
        if ($last === []) {
            return $items;
        }
        $rank = array_flip($last);
        $first = [];
        $tail = [];
        foreach ($items as $item) {
            $key = $keyOf($item);
            if (isset($rank[$key])) {
                $tail[$rank[$key]][] = $item;
            } else {
                $first[] = $item;
            }
        }
        ksort($tail);

        return [...$first, ...array_merge(...array_values($tail) ?: [[]])];
    }
}
