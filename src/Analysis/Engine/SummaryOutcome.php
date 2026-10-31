<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Engine;

use PeakSim\Model\GapCode;
use PeakSim\Model\Summary;

/** What asking the engine for a method summary gave: the summary, or a reason it was refused. */
final readonly class SummaryOutcome
{
    public function __construct(
        public Summary $summary,
        /** The deepest call level reached under this summary (absolute). */
        public int $deepest,
        /** Contains a depth, recursion or budget gap, so it must not be reused. */
        public bool $poisoned,
        /** DEPTH_LIMIT, RECURSION or BUDGET_EXCEEDED when the summary was not computed. */
        public ?GapCode $refusal = null,
    ) {
    }
}
