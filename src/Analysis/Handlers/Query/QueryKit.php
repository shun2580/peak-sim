<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Handlers\Query;

use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Type\ClosureT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Analysis\Type\UnknownT;
use PeakSim\Model\GapCode;
use PhpParser\Node;
use PhpParser\Node\Expr\Closure;

/** The context and the helpers built on it, for one call. Cheap: it holds no state of its own. */
final class QueryKit
{
    public readonly ModelFacts $facts;
    public readonly QueryEvents $events;
    public readonly EagerLoads $eager;

    public function __construct(public readonly EvalContext $ctx)
    {
        $this->facts = new ModelFacts($ctx);
        $this->events = new QueryEvents($ctx);
        $this->eager = new EagerLoads($ctx, $this->facts, $this->events);
    }

    /** A callback argument the analysis can run, or null (a gap has been recorded when one was given but cannot be run). */
    public function callback(Type $type, Node $at, string $what): ?ClosureT
    {
        if ($type instanceof ClosureT) {
            return $type;
        }
        if ($type instanceof UnknownT) {
            return null;
        }
        $this->ctx->gap(GapCode::UNRESOLVED_CALL, $at, "the callback of {$what} is not a closure the analysis can run: what it does is not known");

        return null;
    }
}
