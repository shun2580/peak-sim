<?php

declare(strict_types=1);

namespace PeakSim\Model;

/** One loop level around an event. Bounded loops make no frame. */
final readonly class Frame
{
    public function __construct(
        public Origin $origin,
        public bool $streamed,
        public Site $site,
    ) {
    }

    public function fingerprint(): string
    {
        return $this->origin->fingerprint() . ($this->streamed ? '~' : '') . '@' . $this->site->fingerprint();
    }
}
