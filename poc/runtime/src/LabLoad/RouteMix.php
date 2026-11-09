<?php

declare(strict_types=1);

namespace PeakSim\Runtime\LabLoad;

/**
 * How the total request rate of a lab scenario is spread over the routes of the list (spec 7.2).
 *
 * The routes are taken in the order of the list. `even` gives every route the same share. `first80` gives the first half of
 * the list 80% in all and the second half 20%; `last80` is the reverse. Inside a half every route gets the same share.
 * The list must have an even number of routes (the spec's list has 8) so that "first half" and "second half" are exact.
 */
final class RouteMix
{
    public const EVEN = 'even';
    public const FIRST_80 = 'first80';
    public const LAST_80 = 'last80';

    /** @return list<string> */
    public static function names(): array
    {
        return [self::EVEN, self::FIRST_80, self::LAST_80];
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
        [$first, $last] = match ($mix) {
            self::EVEN => [0.5, 0.5],
            self::FIRST_80 => [0.8, 0.2],
            self::LAST_80 => [0.2, 0.8],
        };
        $shares = [];
        for ($i = 0; $i < $routeCount; ++$i) {
            $shares[] = ($i < $half ? $first : $last) / $half;
        }

        return $shares;
    }
}
