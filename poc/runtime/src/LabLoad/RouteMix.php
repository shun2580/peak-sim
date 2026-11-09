<?php

declare(strict_types=1);

namespace PeakSim\Runtime\LabLoad;

/**
 * How the total request rate of a lab scenario is spread over the routes of the list (spec 7.2).
 *
 * The routes are taken in the order of the list. `even` gives every route the same share. `first80` gives the first half of
 * the list 80% in all and the second half 20%; `last80` is the reverse. `odd80` gives the routes at the odd places of the list
 * (1st, 3rd, 5th, 7th) 80% in all and the routes at the even places 20%. Inside a group every route gets the same share.
 * The list must have an even number of routes (the spec's list has 8) so that "first half" and "second half" are exact.
 */
final class RouteMix
{
    public const EVEN = 'even';
    public const FIRST_80 = 'first80';
    public const LAST_80 = 'last80';
    public const ODD_80 = 'odd80';

    /** @return list<string> */
    public static function names(): array
    {
        return [self::EVEN, self::FIRST_80, self::LAST_80, self::ODD_80];
    }

    /**
     * @return list<float> one share per route, in the order of the list; they add up to 1
     *
     * @throws \InvalidArgumentException an unknown mix, or an odd or too short list
     */
    public static function shares(int $routeCount, string $mix): array
    {
        if (!in_array($mix, self::names(), true)) {
            throw new \InvalidArgumentException(sprintf('unknown mix "%s" (expected one of %s)', $mix, implode(', ', self::names())));
        }
        if ($routeCount < 2 || $routeCount % 2 !== 0) {
            throw new \InvalidArgumentException(sprintf('the route list must have an even number of routes, at least 2 (got %d)', $routeCount));
        }
        $half = intdiv($routeCount, 2);
        $shares = [];
        for ($i = 0; $i < $routeCount; ++$i) {
            $shares[] = match ($mix) {
                self::EVEN => 0.5 / $half,
                self::FIRST_80 => ($i < $half ? 0.8 : 0.2) / $half,
                self::LAST_80 => ($i < $half ? 0.2 : 0.8) / $half,
                self::ODD_80 => ($i % 2 === 0 ? 0.8 : 0.2) / $half, // index 0, 2, ... = the 1st, 3rd, ... place
            };
        }

        return $shares;
    }
}
