<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Data\Stubs;

/** Stands in for an Eloquent model with HasFactory: ::factory()->count(n)->create($attributes). */
final class ModelWithFactory
{
    /** @var list<array{count: int, attributes: array<string, mixed>}> */
    public static array $created = [];

    public static function factory(): object
    {
        return new class {
            private int $count = 1;

            public function count(int $count): self
            {
                $this->count = $count;

                return $this;
            }

            /** @param array<string, mixed> $attributes */
            public function create(array $attributes = []): void
            {
                ModelWithFactory::$created[] = ['count' => $this->count, 'attributes' => $attributes];
            }
        };
    }
}
