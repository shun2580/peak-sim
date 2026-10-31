<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Handlers\Query;

use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Model\CostEvent;
use PeakSim\Model\EventKind;
use PeakSim\Model\MemoryCause;
use PeakSim\Model\MemoryPart;
use PeakSim\Model\Origin;
use PeakSim\Model\QueryPart;
use PhpParser\Node;

/** Makes the CostEvents of the Query handlers. The context adds the loops around them. */
final class QueryEvents
{
    public function __construct(private readonly EvalContext $ctx)
    {
    }

    public function statement(EventKind $kind, Node $at, ?string $table, QueryPart $query, ?MemoryPart $memory = null): void
    {
        $this->ctx->emit(new CostEvent($kind, $this->ctx->site($at), [], $query, $memory, $table, [], $this->ctx->seq()));
    }

    /** Memory with no statement of its own (a payload already held in memory). */
    public function memory(EventKind $kind, Node $at, ?string $table, MemoryPart $memory): void
    {
        $this->ctx->emit(new CostEvent($kind, $this->ctx->site($at), [], null, $memory, $table, [], $this->ctx->seq()));
    }

    public static function materialize(?Origin $origin): MemoryPart
    {
        return new MemoryPart(1, $origin, MemoryCause::Materialize);
    }
}
