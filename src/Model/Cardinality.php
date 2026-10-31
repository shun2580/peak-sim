<?php

declare(strict_types=1);

namespace PeakSim\Model;

/** How big a list is: a constant, proportional to a table, or not known. */
final readonly class Cardinality
{
    public function __construct(
        public CardinalityKind $kind,
        public ?Origin $origin = null,
    ) {
    }

    public static function bounded(): self
    {
        return new self(CardinalityKind::Bounded);
    }

    public static function table(?string $table, ?string $perTable = null): self
    {
        return new self(CardinalityKind::Table, new Origin($table, $perTable));
    }

    public static function unknown(): self
    {
        return new self(CardinalityKind::Unknown);
    }

    public function fingerprint(): string
    {
        return match ($this->kind) {
            CardinalityKind::Bounded => 'bounded',
            CardinalityKind::Table => 'table(' . ($this->origin?->fingerprint() ?? '?') . ')',
            CardinalityKind::Unknown => 'unknown',
        };
    }
}
