<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Route;

/** What a route's middleware says about who may call it. */
final class AuthRequirement
{
    public function __construct(
        public readonly bool $required,
        public readonly ?string $guard,
        public readonly bool $verified,
        public readonly ?string $ability,
        public readonly bool $guestOnly,
    ) {
    }
}
