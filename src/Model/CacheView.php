<?php

declare(strict_types=1);

namespace PeakSim\Model;

/** The two sides of a Cache::remember: what a miss costs and what a hit costs. */
final readonly class CacheView
{
    public function __construct(
        /** @var list<Site> */
        public array $sites,
        /** @var array{query: AxisVerdict, memory: AxisVerdict} */
        public array $onMiss,
        /** @var array{query: AxisVerdict, memory: AxisVerdict} */
        public array $onHit,
        public bool $restoresAllOnHit,
        /** The view the route verdict was taken from. */
        public string $adoptedView = 'miss',
    ) {
    }
}
