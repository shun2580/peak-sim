<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Route;

/**
 * Who calls the route. The decisions live here as plain data so they can be tested without an app; W2's booter
 * does the actual login (`Auth::guard()->setUser()`, and Sanctum's `actingAs` only when the app's own vendor has it).
 *
 * Authorization is measured in two stages: the first request is the ordinary user (or the profile's admin when the
 * profile names one). A 403 gets exactly one more try with the gate bypassed, and the result is marked
 * `gate_bypass`: the policy queries are no longer counted, so it is a different value and only a reference.
 */
final class UserProvider
{
    /**
     * @param list<string>              $middleware
     * @param array<string, mixed>|null $profile    the app's profile (optional). Its `auth_gates` (see `gates()`) add
     *                                              to what the middleware names say; without them the result is the
     *                                              one the names alone give.
     */
    public static function requirement(array $middleware, ?array $profile = null): AuthRequirement
    {
        $required = false;
        $guard = null;
        $verified = false;
        $ability = null;
        $guestOnly = false;
        $as = null;
        $gates = self::gates($profile);

        foreach ($middleware as $entry) {
            [$name, $args] = array_pad(explode(':', $entry, 2), 2, null);
            $short = strtolower((string) preg_replace('/^.*\\\\/', '', $name));

            switch (true) {
                case $name === 'auth' || $short === 'authenticate':
                    $required = true;
                    $guard ??= self::firstArg($args);
                    break;
                case $name === 'auth.basic' || $name === 'auth.session':
                    $required = true;
                    break;
                case $name === 'verified' || $short === 'ensureemailisverified':
                    $required = true;
                    $verified = true;
                    break;
                case $name === 'can' || $short === 'authorize':
                    $required = true;
                    $ability ??= self::firstArg($args);
                    break;
                case $name === 'guest' || $short === 'redirectifauthenticated':
                    $guestOnly = true;
                    break;
            }

            // A gate the profile names is added to the judgement above, never taken from it: a middleware the names
            // already explained keeps what they said (the guard of `auth:api` stays), and the gate fills in what is left.
            foreach ($gates as $gate) {
                if (self::gateMatches($gate['middleware'], $name)) {
                    $required = true;
                    $guard ??= $gate['guard'];
                    $as ??= $gate['as'];
                    break;
                }
            }
        }

        return new AuthRequirement($required, $guard, $verified, $ability, $guestOnly && ! $required, $as);
    }

    /**
     * The `auth_gates` of an app profile: middleware the app wrote for itself that the names `auth`, `authenticate`
     * and the like do not reveal as a login gate. Each entry is
     *
     *   middleware   a name, or a list of names; a middleware matches when its name (the part before any `:argument`)
     *                is that name, or is a class name whose last part is that name (`Vendor\Http\Middleware\Gate`
     *                matches `Gate`), compared without regard to case
     *   guard        optional: the guard to log in on, used when the route's own middleware names none
     *   as           optional: `user` or `admin`, the login of the profile to enter as (default the profile's `login_as`)
     *
     * @param array<string, mixed>|null $profile
     *
     * @return list<array{middleware: list<string>, guard: ?string, as: ?string}>
     */
    public static function gates(?array $profile): array
    {
        $out = [];
        foreach ((array) ($profile['auth_gates'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $names = array_values(array_filter((array) ($entry['middleware'] ?? []), static fn ($n): bool => is_string($n) && $n !== ''));
            if ($names === []) {
                continue;
            }
            $out[] = [
                'middleware' => array_map('strtolower', $names),
                'guard' => isset($entry['guard']) && is_string($entry['guard']) && $entry['guard'] !== '' ? $entry['guard'] : null,
                'as' => isset($entry['as']) && is_string($entry['as']) ? $entry['as'] : null,
            ];
        }

        return $out;
    }

    /** @param list<string> $names lower-case */
    private static function gateMatches(array $names, string $middlewareName): bool
    {
        $lower = strtolower($middlewareName);
        foreach ($names as $name) {
            if ($lower === $name || str_ends_with($lower, '\\' . $name)) {
                return true;
            }
        }

        return false;
    }

    /** One more try with the gate bypassed, and only after a 403 that has not already been retried. */
    public static function shouldRetryWithGateBypass(int $status, bool $alreadyBypassed): bool
    {
        return $status === 403 && ! $alreadyBypassed;
    }

    /**
     * @param array<string, mixed>|null $profile the app's profile (optional): `user` describes the login user and
     *                                           `admin` the administrator (each with the `id` of its row);
     *                                           `login_as` is `admin` to use the admin from the first stage on
     *
     * @return array{login: bool, guard: ?string, source: ?string, admin: bool, login_id: string|int|null, verified: bool, ability: ?string}
     *         login_id is the key of the row to log in as, or null for the first row of the user model
     */
    public static function plan(AuthRequirement $requirement, ?array $profile): array
    {
        if (! $requirement->required) {
            return ['login' => false, 'guard' => null, 'source' => null, 'admin' => false, 'login_id' => null, 'verified' => false, 'ability' => null];
        }

        $admin = ($requirement->as ?? $profile['login_as'] ?? null) === 'admin';
        $id = $profile[$admin ? 'admin' : 'user']['id'] ?? null;

        return [
            'login' => true,
            'guard' => $requirement->guard,
            'source' => isset($profile['user']) ? 'profile' : 'generator',
            'admin' => $admin,
            'login_id' => is_int($id) || is_string($id) ? $id : null,
            'verified' => $requirement->verified,
            'ability' => $requirement->ability,
        ];
    }

    private static function firstArg(?string $args): ?string
    {
        if ($args === null || $args === '') {
            return null;
        }
        $first = trim(explode(',', $args)[0]);

        return $first === '' ? null : $first;
    }
}
