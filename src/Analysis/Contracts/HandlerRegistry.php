<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Contracts;

final class HandlerRegistry
{
    /** @var list<array{0: int, 1: int, 2: CallHandler}> priority, registration order, handler */
    private array $entries = [];

    private int $registered = 0;

    public function register(CallHandler $h, int $priority): void
    {
        $this->entries[] = [$priority, $this->registered++, $h];
        usort($this->entries, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    }

    /** @return list<CallHandler> by priority ascending, then registration order */
    public function handlers(): array
    {
        return array_map(static fn (array $e): CallHandler => $e[2], $this->entries);
    }
}
