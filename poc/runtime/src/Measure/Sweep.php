<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

/** The databases of one sweep of one route: axis N (rows of the scaled table) or M (children per parent row). */
final class Sweep
{
    /** @param list<DatabasePoint> $points in measuring order; on axis N the empty system (scale 0) comes first */
    public function __construct(public readonly string $axis, public readonly array $points)
    {
        if (!in_array($axis, ['N', 'M'], true)) {
            throw new \InvalidArgumentException('a sweep runs along N or M');
        }
    }
}
