<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

/** What the preparation needs from the booted app that the route table does not say. */
interface AppFacts
{
    /**
     * The login for a guard as `Model\Class:key[:guard]`: the row of the guard's user model with this key (the first
     * row when no key is given), or null when the measurement database has no such row.
     */
    public function loginUser(?string $guard, string|int|null $id = null): ?string;

    /**
     * The validation rules of the FormRequest the action takes, or null when it takes none (or they cannot be read).
     *
     * @return array<string, mixed>|null
     */
    public function bodyRules(?string $action): ?array;
}
