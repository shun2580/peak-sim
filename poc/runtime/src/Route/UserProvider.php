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
    /** @param list<string> $middleware */
    public static function requirement(array $middleware): AuthRequirement
    {
        $required = false;
        $guard = null;
        $verified = false;
        $ability = null;
        $guestOnly = false;

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
        }

        return new AuthRequirement($required, $guard, $verified, $ability, $guestOnly && ! $required);
    }

    /** One more try with the gate bypassed, and only after a 403 that has not already been retried. */
    public static function shouldRetryWithGateBypass(int $status, bool $alreadyBypassed): bool
    {
        return $status === 403 && ! $alreadyBypassed;
    }

    /**
     * @param array<string, mixed>|null $profile the app's profile (optional): `user` describes the login user,
     *                                           `login_as` is `admin` to use the admin from the first stage on
     *
     * @return array{login: bool, guard: ?string, source: ?string, admin: bool, verified: bool, ability: ?string}
     */
    public static function plan(AuthRequirement $requirement, ?array $profile): array
    {
        if (! $requirement->required) {
            return ['login' => false, 'guard' => null, 'source' => null, 'admin' => false, 'verified' => false, 'ability' => null];
        }

        return [
            'login' => true,
            'guard' => $requirement->guard,
            'source' => isset($profile['user']) ? 'profile' : 'generator',
            'admin' => ($profile['login_as'] ?? null) === 'admin',
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
