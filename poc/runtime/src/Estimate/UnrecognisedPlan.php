<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Estimate;

/** A plan has a node or a field `PlanReads` does not name and cannot walk without. */
final class UnrecognisedPlan extends \RuntimeException
{
}
