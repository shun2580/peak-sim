<?php

declare(strict_types=1);

namespace PeakSim\Runtime\LabLoad;

/**
 * How the total request rate of a lab scenario is spread over the routes of the list (spec 7.2).
 *
 * The routes are taken in the order of the list. `even` gives every route the same share. `first80` gives the first half of
 * the list 80% in all and the second half 20%; `last80` is the reverse. `odd80` gives the routes at the odd places of the list
 * (1st, 3rd, 5th, 7th) 80% in all and the routes at the even places 20%; `even80` is the reverse (the 2nd, 4th, 6th, 8th get 80%, the
 * odd places 20%; section 7.4.2's s-5). Inside a group every route gets the same share.
 * The list must have an even number of routes (the spec's list has 8) so that "first half" and "second half" are exact.
 *
 * `places1235` and `places1345` (section 7.4.2's s-4 and s-5) name the places that get 80% instead of splitting the list by parity: 1st, 2nd,
 * 3rd and 5th (`places1235`) or 1st, 3rd, 4th and 5th (`places1345`) get 80% in all (0.2 each), the other four places get 20% in all
 * (0.05 each). They are fixed by the order of the list alone: in the spec's list the ZIP export is the 2nd place and the book page the
 * 4th, so `places1235` gives the ZIP 0.2 and the book page 0.05, and `places1345` the book page 0.2 and the ZIP 0.05. (`odd80` and
 * `even80` put both of those two routes in the same group, so they could not tell the two apart.)
 *
 * `bookpage80zip0` is the one mix that names its routes instead of counting places: the book page route (`GET /books/{slug}`)
 * gets 80%, the book ZIP export route (`GET /books/{bookSlug}/export/zip`) gets 0%, and the other routes of the list share the
 * remaining 20% equally (1/30 each of 8 routes minus those two). Pointing at the two routes by name means a list in another
 * order cannot swap them. It needs the list itself, so `shares` takes it as a third argument for this mix only.
 */
final class RouteMix
{
    public const EVEN = 'even';
    public const FIRST_80 = 'first80';
    public const LAST_80 = 'last80';
    public const ODD_80 = 'odd80';
    public const EVEN_80 = 'even80';
    public const PLACES_1235 = 'places1235';
    public const PLACES_1345 = 'places1345';
    public const BOOK_PAGE_80_ZIP_0 = 'bookpage80zip0';

    /** The places (0-based) of the list that get 80% in all, for the mixes that name places. */
    private const HEAVY_PLACES = [
        self::PLACES_1235 => [0, 1, 2, 4],
        self::PLACES_1345 => [0, 2, 3, 4],
    ];

    public const BOOK_PAGE_ROUTE = 'GET /books/{slug}';
    public const ZIP_ROUTE = 'GET /books/{bookSlug}/export/zip';

    /** The mixes that spread the rate by place in the list (they need no route names). @return list<string> */
    public static function names(): array
    {
        return [self::EVEN, self::FIRST_80, self::LAST_80, self::ODD_80, self::EVEN_80, self::PLACES_1235, self::PLACES_1345];
    }

    /** The mixes that point at routes by name (they need the list of routes). @return list<string> */
    public static function namedMixes(): array
    {
        return [self::BOOK_PAGE_80_ZIP_0];
    }

    /**
     * @param list<string> $routes "METHOD uri" of the routes, in the order of the list; read only by a mix that names its routes
     *
     * @return list<float> one share per route, in the order of the list; they add up to 1
     *
     * @throws \InvalidArgumentException an unknown mix, an odd or too short list, or a named mix whose routes are not in the list
     */
    public static function shares(int $routeCount, string $mix, array $routes = []): array
    {
        if (in_array($mix, self::namedMixes(), true)) {
            return self::namedShares($routeCount, $routes);
        }
        if (!in_array($mix, self::names(), true)) {
            throw new \InvalidArgumentException(sprintf('unknown mix "%s" (expected one of %s)', $mix, implode(', ', array_merge(self::names(), self::namedMixes()))));
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
                self::EVEN_80 => ($i % 2 === 1 ? 0.8 : 0.2) / $half, // index 1, 3, ... = the 2nd, 4th, ... place
                self::PLACES_1235, self::PLACES_1345 => (in_array($i, self::HEAVY_PLACES[$mix], true) ? 0.8 : 0.2) / $half,
            };
        }

        return $shares;
    }

    /**
     * @param list<string> $routes
     *
     * @return list<float>
     */
    private static function namedShares(int $routeCount, array $routes): array
    {
        if (count($routes) !== $routeCount) {
            throw new \InvalidArgumentException(sprintf('the mix %s needs the list of routes (got %d names for %d routes)', self::BOOK_PAGE_80_ZIP_0, count($routes), $routeCount));
        }
        foreach ([self::BOOK_PAGE_ROUTE, self::ZIP_ROUTE] as $need) {
            if (count(array_keys($routes, $need, true)) !== 1) {
                throw new \InvalidArgumentException(sprintf('the mix %s needs the route "%s" exactly once in the list', self::BOOK_PAGE_80_ZIP_0, $need));
            }
        }
        if ($routeCount < 3) {
            throw new \InvalidArgumentException(sprintf('the mix %s needs at least one route besides the book page and the ZIP export', self::BOOK_PAGE_80_ZIP_0));
        }
        $others = $routeCount - 2;
        $shares = [];
        foreach ($routes as $route) {
            $shares[] = match ($route) {
                self::BOOK_PAGE_ROUTE => 0.8,
                self::ZIP_ROUTE => 0.0,
                default => 0.2 / $others,
            };
        }

        return $shares;
    }
}
