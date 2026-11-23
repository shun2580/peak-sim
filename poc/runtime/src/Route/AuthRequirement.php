<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Route;

/**
 * What a route's middleware says about who may call it. `as` is the login (`user` or `admin`) a profile's `auth_gates`
 * entry says the route's gate wants; it is null for every requirement read from the middleware alone.
 */
final class AuthRequirement
{
    public function __construct(
        public readonly bool $required,
        public readonly ?string $guard,
        public readonly bool $verified,
        public readonly ?string $ability,
        public readonly bool $guestOnly,
        public readonly ?string $as = null,
    ) {
    }
}
