<?php

declare(strict_types=1);

namespace PeakSim\Prototype;

/** Variable types and loop nesting at one point of a method body. */
final class Scope
{
    /** @var array<string, Type|null> */
    public array $vars = [];

    /** @param list<string|null> $tables tables iterated by the enclosing loops, outermost first */
    public function __construct(
        public readonly string $file,
        public readonly string $class,
        public readonly Summary $summary,
        public readonly int $level,
        public int $depth = 0,
        public array $tables = [],
    ) {
    }

    public function loop(?string $table): self
    {
        $inner = clone $this;
        $inner->depth++;
        $inner->tables[] = $table;

        return $inner;
    }

    public function sameLevel(): self
    {
        return clone $this;
    }
}
