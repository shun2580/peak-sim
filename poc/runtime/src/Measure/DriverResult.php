<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

use PeakSim\Runtime\Report\RunReport;
use PeakSim\Runtime\Run\Attempt;

/** What a run produced. `exit` is the exit code of `bin/measure`; a stopped run still carries what it measured. */
final class DriverResult
{
    /**
     * @param list<Attempt>                    $attempts every attempt of every route
     * @param list<array<string, mixed>>       $timings  per database: how long it took to build and to measure
     * @param list<array<string, mixed>>       $builds   what the builder said about each database it built
     * @param array<string, array<string, mixed>> $learnedBodies the body that got past validation, by "METHOD route": the one the
     *                                                          measurement used when it differs from the one the task was made with
     */
    public function __construct(
        public readonly int $exit,
        public readonly ?string $message,
        public readonly RunReport $report,
        public readonly array $attempts,
        public readonly array $timings,
        public readonly array $builds,
        public readonly array $learnedBodies = [],
    ) {
    }
}
