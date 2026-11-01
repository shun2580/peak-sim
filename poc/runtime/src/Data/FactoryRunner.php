<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Data;

use InvalidArgumentException;
use Throwable;

/**
 * Creates a few rows through the app's own Eloquent factory (design 1.2.4). Factories are for the handful of
 * rows the schema based generator is poor at (a user to log in as): they insert one row at a time and need
 * Faker, a dev dependency that a --no-dev install does not have.
 *
 * The caller has booted the app (and checked the connection is the measurement database) before calling run().
 */
final class FactoryRunner
{
    /** @var \Closure(): bool */
    private readonly \Closure $fakerCheck;

    /** @param ?callable(): bool $fakerCheck tells whether Faker is installed (default: look for its class) */
    public function __construct(?callable $fakerCheck = null)
    {
        $this->fakerCheck = $fakerCheck !== null
            ? \Closure::fromCallable($fakerCheck)
            : static fn (): bool => class_exists(\Faker\Generator::class);
    }

    public function fakerAvailable(): bool
    {
        return ($this->fakerCheck)();
    }

    /**
     * @param class-string|string  $modelClass
     * @param array<string, mixed> $attributes overrides for the factory's definition
     */
    public function run(string $modelClass, int $count, array $attributes = []): FactoryResult
    {
        if ($count < 1) {
            throw new InvalidArgumentException('count must be at least 1');
        }
        if (!class_exists($modelClass) || !method_exists($modelClass, 'factory')) {
            return new FactoryResult(0, Reason::NO_FACTORY, TableOutcome::cut($modelClass . ' has no factory'));
        }
        if (!$this->fakerAvailable()) {
            return new FactoryResult(0, Reason::FAKER_MISSING, 'Faker is not installed (a --no-dev install)');
        }

        try {
            $modelClass::factory()->count($count)->create($attributes);
        } catch (Throwable $e) {
            return new FactoryResult(0, Reason::FACTORY_ERROR, TableOutcome::cut($e->getMessage()));
        }

        return new FactoryResult($count);
    }
}
