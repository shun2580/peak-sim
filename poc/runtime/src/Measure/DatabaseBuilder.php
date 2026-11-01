<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

/** Makes a measurement database (migrated, filled by the recipe) and drops it. Only measurement databases. */
interface DatabaseBuilder
{
    /**
     * @return array<string, mixed> what was built (counts, seconds), for the run record
     *
     * @throws BuildFailure
     */
    public function build(DatabasePoint $point): array;

    public function drop(DatabasePoint $point): void;
}
