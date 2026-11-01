<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Route;

/**
 * Which routes an `unfillable` entry of an app profile is about. An entry may carry
 *
 *   routes   a route key, any of (every key written must match; within one key any value may):
 *              uri_prefix     the route's uri, without its leading slash, starts with one of these
 *              middleware     the route has a middleware of this name (the part before any `:argument`)
 *              action_prefix  the route's action starts with one of these
 *   params   the names of route arguments the entry explains
 *
 * An entry with `routes` and no `params` explains the whole route: it cannot be prepared, for the entry's reason. An
 * entry with `params` explains those arguments (in the routes it names, or in every route when it names none). An
 * entry with neither is a note and matches nothing.
 */
final class ProfileRoutes
{
    public const ROUTE_KEYS = ['uri_prefix', 'middleware', 'action_prefix'];

    /**
     * The first entry that explains this whole route, or null.
     *
     * @param array<string, mixed> $profile
     * @param list<string>         $middleware
     *
     * @return array{label: string, reason: string, why: string}|null
     */
    public static function explaining(array $profile, string $uri, array $middleware, ?string $action): ?array
    {
        foreach ((array) ($profile['unfillable'] ?? []) as $label => $entry) {
            if (!is_array($entry) || !isset($entry['routes']) || !empty($entry['params']) || !is_string($entry['reason'] ?? null)) {
                continue;
            }
            if (self::covers($entry, $uri, $middleware, $action)) {
                return ['label' => (string) $label, 'reason' => $entry['reason'], 'why' => (string) ($entry['why'] ?? '')];
            }
        }

        return null;
    }

    /**
     * Whether the entry's route key (if any) matches this route. An entry with no `routes` covers every route.
     *
     * @param array<string, mixed> $entry
     * @param list<string>         $middleware
     */
    public static function covers(array $entry, string $uri, array $middleware, ?string $action): bool
    {
        $routes = $entry['routes'] ?? null;
        if (!is_array($routes) || $routes === []) {
            return !isset($entry['routes']);
        }
        $path = ltrim($uri, '/');
        $names = array_map(static fn (string $m): string => explode(':', $m, 2)[0], $middleware);

        foreach ($routes as $key => $values) {
            $values = array_values(array_filter((array) $values, 'is_string'));
            $matched = match ($key) {
                'uri_prefix' => self::anyPrefix($path, $values),
                'middleware' => array_intersect($names, $values) !== [],
                'action_prefix' => $action !== null && self::anyPrefix($action, $values),
                default => false,
            };
            if (!$matched) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $prefixes */
    private static function anyPrefix(string $text, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($text, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
