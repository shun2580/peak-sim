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
 */
final class ScenarioPlan
{
    public const DURATION_SEC = 600;
    public const WARMUP_SEC = 120;

    /**
     * @param list<string> $routes "METHOD uri" of the routes, in the order of the list (the list is read, not changed)
     *
     * @return array{mix: string, rps: float, duration_sec: int, warmup_sec: int, routes: list<array{route: string, share: float, rps: float, rate: int, time_unit: string}>}
     *
     * @throws \InvalidArgumentException a non-positive rps, a window that does not fit, a duplicate route, or a rate k6 cannot express
     */
    public static function build(array $routes, string $mix, float $rps, int $durationSec = self::DURATION_SEC, int $warmupSec = self::WARMUP_SEC): array
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
        $shares = RouteMix::shares(count($routes), $mix, array_values($routes));
        $out = [];
        foreach ($routes as $i => $route) {
            if ($shares[$i] <= 0.0) {
                continue; // a route with no share is not sent (k6 cannot run a stream of 0 per second); no mix but a named one gives 0
            }
            $routeRps = $rps * $shares[$i];
            [$rate, $unit] = self::rate($routeRps);
            $out[] = ['route' => $route, 'share' => $shares[$i], 'rps' => $routeRps, 'rate' => $rate, 'time_unit' => $unit];
        }

        return ['mix' => $mix, 'rps' => $rps, 'duration_sec' => $durationSec, 'warmup_sec' => $warmupSec, 'routes' => $out];
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
