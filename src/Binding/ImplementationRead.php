<?php

declare(strict_types=1);

namespace PeakSim\Binding;

use PeakSim\Model\Site;

/** The classes a binding's second argument can produce, or why that could not be told. */
final readonly class ImplementationRead
{
    /** @param array<string, Site> $classes class => where it is named; the first mention wins */
    private function __construct(
        public array $classes,
        public ?string $failure,
    ) {
    }

    /** @param array<string, Site> $classes */
    public static function found(array $classes): self
    {
        return new self($classes, null);
    }

    public static function failed(string $failure): self
    {
        return new self([], $failure);
    }
}
