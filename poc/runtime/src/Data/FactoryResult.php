<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Data;

final class FactoryResult
{
    public function __construct(
        public readonly int $created,
        public readonly ?string $reason = null,
        public readonly ?string $detail = null,
    ) {
    }

    public function ok(): bool
    {
        return $this->reason === null;
    }
}
