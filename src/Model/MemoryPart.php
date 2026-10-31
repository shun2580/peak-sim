<?php

declare(strict_types=1);

namespace PeakSim\Model;

final readonly class MemoryPart
{
    public function __construct(
        /** The degree this event adds to memory by itself (get() = 1, cursor on a buffering driver = 1). */
        public int $ownDegree,
        public ?Origin $ownOrigin,
        public MemoryCause $cause,
        /** For accumulation: counting starts at frames[retainFrom]. */
        public ?int $retainFrom = null,
    ) {
    }

    public function fingerprint(): string
    {
        return $this->cause->value . ':' . $this->ownDegree . ':' . ($this->ownOrigin?->fingerprint() ?? '-')
            . ':' . ($this->retainFrom ?? '-');
    }
}
