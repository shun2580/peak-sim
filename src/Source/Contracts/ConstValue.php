<?php

declare(strict_types=1);

namespace PeakSim\Source;

/** Known(value) or NotConstant. */
final readonly class ConstValue
{
    private function __construct(
        public bool $known,
        public mixed $value,
    ) {
    }

    public static function known(mixed $value): self
    {
        return new self(true, $value);
    }

    public static function notConstant(): self
    {
        return new self(false, null);
    }
}
