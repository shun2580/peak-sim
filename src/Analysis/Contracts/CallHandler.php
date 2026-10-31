<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Contracts;

use PeakSim\Analysis\Type\Type;

/** Gives one family of calls their meaning. The first handler (by priority) that handles() a call takes it. */
interface CallHandler
{
    public function handles(CallSite $c, EvalContext $ctx): bool;

    /** Returns UnknownT only through EvalContext::gap() (invariant I1). */
    public function handle(CallSite $c, EvalContext $ctx): Type;
}
