<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

use PeakSim\Runtime\Route\RouteSpec;

/** Decides which databases a route is measured on, and what is in them. */
interface DataPlanner
{
    /**
     * @param list<int> $scales the scales of the main sweep (ascending)
     *
     * @return list<Sweep>
     */
    public function sweepsFor(RouteSpec $spec, array $scales): array;

    /**
     * The database the route inspection runs on: a small whole system, so every table has rows to pick a first row from.
     *
     * @param list<int> $scales
     */
    public function inspectionPoint(array $scales): DatabasePoint;
}
