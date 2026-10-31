<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Engine;

final readonly class EngineOptions
{
    public function __construct(
        /** The route's action is level 1; a call at level maxDepth + 1 is a DEPTH_LIMIT gap. */
        public int $maxDepth = 20,
        /** How many method summaries may be computed in one engine before BUDGET_EXCEEDED. */
        public int $budget = 50000,
        /** Tests: record every UnknownT that was not made through gap() (invariant I1) in Engine::violations(). */
        public bool $auditUnknown = false,
    ) {
    }
}
