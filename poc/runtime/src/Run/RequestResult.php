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
        public readonly ?string $note = null,
        /** @var list<string> the names of the fields a 422 answer says failed */
        public readonly array $errorKeys = [],
    ) {
    }
}
