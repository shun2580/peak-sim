<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Run;

final class RequestResult
{
    public function __construct(
        public readonly ?int $status,
        public readonly int $bytes,
        public readonly int $peakMem,
        public readonly ?\Throwable $exception,
        public readonly ?string $redirectPath = null,
    ) {
    }
}
