<?php

declare(strict_types=1);

namespace PeakSim\Model;

use PeakSim\Analysis\Type\Type;

/** What walking one method produced. Events are relative to the method's entry. */
final readonly class Summary
{
    /**
     * @param list<CostEvent> $events
     * @param list<Gap> $gaps
     * @param list<Assumption> $assumptions
     * @param list<Site> $trace sites in the order they were entered
     */
    public function __construct(
        public array $events,
        public array $gaps,
        public array $assumptions,
        public array $trace,
        public Type $returnType,
        public bool $truncated = false,
    ) {
    }
}
