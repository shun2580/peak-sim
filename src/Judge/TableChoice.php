<?php

declare(strict_types=1);

namespace PeakSim\Judge;

/** Which tables N and M are, and the tables that tied with N. */
final readonly class TableChoice
{
    /** @param list<string> $alternatives other tables that tie with N on degree, axis and kind */
    public function __construct(
        public ?string $n,
        public ?string $m,
        public ?string $per,
        public array $alternatives,
    ) {
    }
}
