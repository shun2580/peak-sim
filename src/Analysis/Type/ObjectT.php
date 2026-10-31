<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Type;

/** An instance of an app class (injected or created with new). $class is the declared type, before binding. */
final class ObjectT extends Type
{
    public function __construct(
        public readonly string $class,
    ) {
    }

    public function withClass(string $class): self
    {
        return new self($class);
    }

    public function fingerprint(): string
    {
        return 'object:' . $this->class;
    }
}
