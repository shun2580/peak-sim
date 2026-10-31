<?php

declare(strict_types=1);

namespace PeakSim\Source;

/** Found(MethodRef) or NotFound(reason). */
final readonly class MethodResolution
{
    private function __construct(
        public ?MethodRef $method,
        public ?MethodMiss $miss,
    ) {
    }

    public static function found(MethodRef $method): self
    {
        return new self($method, null);
    }

    public static function notFound(MethodMiss $reason): self
    {
        return new self(null, $reason);
    }

    public function isFound(): bool
    {
        return $this->method !== null;
    }
}
