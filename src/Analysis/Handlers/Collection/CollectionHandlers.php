<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Handlers\Collection;

use PeakSim\Analysis\Contracts\HandlerRegistry;

/** Registers the Collection handlers together: they share a priority and are asked in this order. */
final class CollectionHandlers
{
    public static function register(HandlerRegistry $registry, int $priority = 40): void
    {
        $registry->register(new CollectionOps(), $priority);
        $registry->register(new HigherOrderProxies($registry), $priority);
        $registry->register(new ArrayFunctions(), $priority);
    }
}
