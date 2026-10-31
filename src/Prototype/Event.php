<?php

declare(strict_types=1);

namespace PeakSim\Prototype;

/**
 * One place that issues a query or loads rows. depth counts the enclosing loops over
 * database rows; loopTables lists the tables those loops iterate, outermost first.
 */
final class Event
{
    public const QUERY = 'query';
    public const LAZY = 'lazy';
    public const EAGER = 'eager';

    /** @param list<string|null> $loopTables */
    public function __construct(
        public readonly string $kind,
        public readonly int $depth,
        public readonly array $loopTables,
        public readonly ?string $table,
        public readonly bool $unbounded,
        public readonly string $file,
        public readonly int $line,
        public readonly string $note,
    ) {
    }

    /** The same event seen from a call site that is itself $depth loops deep. @param list<string|null> $outerTables */
    public function shifted(int $depth, array $outerTables): self
    {
        return new self(
            $this->kind,
            $this->depth + $depth,
            [...$outerTables, ...$this->loopTables],
            $this->table,
            $this->unbounded,
            $this->file,
            $this->line,
            $this->note,
        );
    }
}
