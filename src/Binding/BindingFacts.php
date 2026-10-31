<?php

declare(strict_types=1);

namespace PeakSim\Binding;

use PeakSim\Model\Site;

/** Everything read from the providers, before any question is asked. */
final class BindingFacts
{
    /** @var list<BindingEntry> in provider order, then source order */
    private array $entries = [];

    /** @var array<string, true> */
    private array $seen = [];

    /** @var array<string, array{0: Site, 1: string}> abstract => first extend()/afterResolving() */
    private array $decorated = [];

    /** @var list<array{0: Site, 1: string}> registrations that may touch any abstract but could not be read */
    private array $unreadable = [];

    public function add(BindingEntry $entry): void
    {
        $key = $entry->key();
        if (!isset($this->seen[$key])) {
            $this->seen[$key] = true;
            $this->entries[] = $entry;
        }
    }

    public function decorate(string $abstract, Site $at, string $how): void
    {
        $this->decorated[$abstract] ??= [$at, $how];
    }

    public function unreadable(Site $at, string $reason): void
    {
        $this->unreadable[] = [$at, $reason];
    }

    /** @return list<BindingEntry> */
    public function entriesFor(string $abstract): array
    {
        return array_values(array_filter($this->entries, static fn (BindingEntry $e): bool => $e->abstract === $abstract));
    }

    /** @return array{0: Site, 1: string}|null */
    public function decoration(string $abstract): ?array
    {
        return $this->decorated[$abstract] ?? null;
    }

    /** @return array{0: Site, 1: string}|null */
    public function firstUnreadable(): ?array
    {
        return $this->unreadable[0] ?? null;
    }
}
