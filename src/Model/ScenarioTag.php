<?php

declare(strict_types=1);

namespace PeakSim\Model;

/** Marks an event or a gap as belonging to the miss or the hit side of one cache call. */
final readonly class ScenarioTag
{
    public const MISS = 'miss';
    public const HIT = 'hit';

    public function __construct(
        public int $cacheSite,
        /** 'miss' | 'hit' */
        public string $side,
    ) {
        if ($side !== self::MISS && $side !== self::HIT) {
            throw new \InvalidArgumentException("side must be 'miss' or 'hit'");
        }
    }

    public function fingerprint(): string
    {
        return $this->cacheSite . ':' . $this->side;
    }
}
