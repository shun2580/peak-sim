<?php

declare(strict_types=1);

namespace PeakSim\Runtime\LabLoad;

/**
 * The plan the k6 scenario file reads: one constant-arrival-rate stream per route, so the mix is exact and not left to chance.
 *
 * A route's rate is the total rps times its share. k6 wants an integer rate per time unit, so a rate that is not a whole
 * number of requests per second is written as a count per 1000 seconds (the spacing between requests is the same).
 * A route whose share is 0 is left out of the plan: its rate is 0 requests, so there is nothing to send.
 * Nothing here decides what a good rps is; it only turns "R requests per second, spread like this" into the file.
 *
 * With an allocation (the prediction's `allocation.rps_after` of the scenario, decisions.md D226 and D227: the routes that cannot run
 * to the end at the scenario's count are taken out of the mix and their rps handed to the routes that stay) the shares are not the mix's
 * but the allocation's: a route's share is its rps in the allocation divided by the sum of them, a route of the list that is not in the
 * allocation is not sent, and the plan says so under `allocation`. Without one the plan is exactly what it always was (no extra key).
 */
final class ScenarioPlan
{
    public const DURATION_SEC = 600;
    public const WARMUP_SEC = 120;

    /**
     * @param list<string> $routes "METHOD uri" of the routes, in the order of the list (the list is read, not changed)
     * @param array{rps_after: array<string, float|int>, dropped?: list<string>}|null $allocation the shares of an allocated B scenario (see the class comment); null = the mix's
     *
     * @return array{mix: string, rps: float, duration_sec: int, warmup_sec: int, routes: list<array{route: string, share: float, rps: float, rate: int, time_unit: string}>, allocation?: array{routes_sent: list<string>, routes_not_sent: list<string>, rule: string}}
     *
     * @throws \InvalidArgumentException a non-positive rps, a window that does not fit, a duplicate route, a rate k6 cannot express, or an allocation that does not fit the list
     */
    public static function build(array $routes, string $mix, float $rps, int $durationSec = self::DURATION_SEC, int $warmupSec = self::WARMUP_SEC, ?array $allocation = null): array
    {
        if (!is_finite($rps) || $rps <= 0.0) {
            throw new \InvalidArgumentException('rps must be a positive number');
        }
        if ($warmupSec < 0 || $warmupSec >= $durationSec) {
            throw new \InvalidArgumentException('the discarded start must be shorter than the scenario');
        }
        if (count(array_unique($routes)) !== count($routes)) {
            throw new \InvalidArgumentException('a route is in the list twice');
        }
        if ($allocation === null) {
            $shares = RouteMix::shares(count($routes), $mix, array_values($routes));
        } else {
            $shares = self::allocatedShares(array_values($routes), $allocation);
        }
        $out = [];
        foreach ($routes as $i => $route) {
            if ($shares[$i] <= 0.0) {
                continue; // a route with no share is not sent (k6 cannot run a stream of 0 per second); no mix but a named one gives 0
            }
            $routeRps = $rps * $shares[$i];
            [$rate, $unit] = self::rate($routeRps);
            $out[] = ['route' => $route, 'share' => $shares[$i], 'rps' => $routeRps, 'rate' => $rate, 'time_unit' => $unit];
        }

        $plan = ['mix' => $mix, 'rps' => $rps, 'duration_sec' => $durationSec, 'warmup_sec' => $warmupSec, 'routes' => $out];
        if ($allocation !== null) {
            $sent = array_column($out, 'route');
            $plan['allocation'] = [
                'routes_sent' => $sent,
                'routes_not_sent' => array_values(array_diff($routes, $sent)),
                'rule' => "the shares are the prediction's allocation (rps_after of the scenario), not the mix's: a route's share is its rps there over the sum; a route of the list that is not there is not sent",
            ];
        }

        return $plan;
    }

    /**
     * The shares of an allocation: each route's rps in `rps_after` over their sum, 0 for a route of the list that is not in it.
     * Every route in the allocation must be in the list, every rps must be a positive number, and a route named in `dropped` must not
     * be in `rps_after`: anything else means the prediction and the list disagree, and nothing is planned.
     *
     * @param list<string> $routes
     * @param array{rps_after?: mixed, dropped?: mixed} $allocation
     *
     * @return list<float> one share per route of the list, in its order
     *
     * @throws \InvalidArgumentException
     */
    private static function allocatedShares(array $routes, array $allocation): array
    {
        $after = $allocation['rps_after'] ?? null;
        if (!is_array($after) || $after === []) {
            throw new \InvalidArgumentException('the allocation has no rps_after');
        }
        $sum = 0.0;
        foreach ($after as $route => $value) {
            if (!is_string($route) || !in_array($route, $routes, true)) {
                throw new \InvalidArgumentException('the allocation has a route that is not in the route list: ' . (string) $route);
            }
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || (float) $value <= 0.0) {
                throw new \InvalidArgumentException('the allocation gives route ' . $route . ' an rps that is not a positive number');
            }
            $sum += (float) $value;
        }
        $dropped = $allocation['dropped'] ?? [];
        if (!is_array($dropped)) {
            throw new \InvalidArgumentException('the allocation\'s dropped routes are not a list');
        }
        foreach ($dropped as $route) {
            if (is_string($route) && array_key_exists($route, $after)) {
                throw new \InvalidArgumentException('the route ' . $route . ' is dropped by the allocation and also has an rps in it');
            }
        }
        $shares = [];
        foreach ($routes as $route) {
            $shares[] = array_key_exists($route, $after) ? (float) $after[$route] / $sum : 0.0;
        }

        return $shares;
    }

    /** The longest time unit tried, in seconds. Every rung of the lab's ladder is found within 1000s. */
    private const MAX_UNIT_SEC = 10000;

    /**
     * Per second first, then per 1000 seconds (what every earlier plan used), then the shortest other whole number of
     * seconds in which the rate is a whole count: 0.00625 rps is 1 per 160s, 0.00875 rps is 7 per 800s.
     *
     * @return array{0: int, 1: string}
     */
    private static function rate(float $rps): array
    {
        $factors = array_merge([1, 1000], array_diff(range(2, self::MAX_UNIT_SEC), [1000]));
        foreach ($factors as $factor) {
            $rate = (int) round($rps * $factor);
            if ($rate >= 1 && abs($rate / $factor - $rps) <= $rps * 0.001) {
                return [$rate, $factor . 's'];
            }
        }

        throw new \InvalidArgumentException(sprintf('a rate of %.6f rps per route cannot be written as a whole count to within 0.1%%', $rps));
    }
}
