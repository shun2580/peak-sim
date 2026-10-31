<?php

declare(strict_types=1);

namespace PeakSim\Model;

final readonly class AxisVerdict
{
    public function __construct(
        public Growth $growth,
        /** 3 or more is shown as ">=3". */
        public int $degree,
        /** Null when the growth is unknown. */
        public ?Confidence $confidence,
        public ?Growth $lowerBound,
        public ?Site $dominantSite,
    ) {
    }
}
