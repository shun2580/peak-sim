<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

/** Measures one route on one database, in one process. */
interface OneRunner
{
    public function run(MeasureRequest $request): OneResult;
}
