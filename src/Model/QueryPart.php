<?php

declare(strict_types=1);

namespace PeakSim\Model;

final readonly class QueryPart
{
    public function __construct(
        /** The event itself issues N statements (e.g. chunk: N/c). */
        public bool $ownUnbounded = false,
        public ?Origin $ownOrigin = null,
        /** Statements per call (firstOrCreate = 2, paginate = 2). */
        public int $statements = 1,
        public bool $conditional = false,
        public ?int $chunkSize = null,
    ) {
    }

    public function fingerprint(): string
    {
        return ($this->ownUnbounded ? 'U' : 'B') . ':' . ($this->ownOrigin?->fingerprint() ?? '-')
            . ':' . $this->statements . ($this->conditional ? 'c' : '') . ':' . ($this->chunkSize ?? '-');
    }
}
