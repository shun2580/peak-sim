<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Type;

/** A JsonResource and the value it wraps. $many is true for XxxResource::collection(...). */
final class ResourceT extends Type
{
    public function __construct(
        public readonly string $resourceClass,
        public readonly Type $inner,
        public readonly bool $many = false,
    ) {
    }

    public function withInner(Type $inner): self
    {
        return new self($this->resourceClass, $inner, $this->many);
    }

    public function fingerprint(): string
    {
        return 'resource:' . $this->resourceClass . ($this->many ? '[]' : '') . '(' . $this->inner->fingerprint() . ')';
    }
}
