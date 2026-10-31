<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Handlers\Query;

use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Type\UnknownT;
use PeakSim\Input\RelationInfo;
use PeakSim\Model\AssumptionCode;
use PeakSim\Model\GapCode;
use PeakSim\Source\ModelDescriptor;
use PeakSim\Source\ParseFailed;
use PhpParser\Node;

/**
 * What the Query handlers need to know about a model, with the gaps and assumptions that go with not knowing.
 * A model the export does not describe is a gap (MODEL_INFO_UNAVAILABLE), never a guess.
 */
final class ModelFacts
{
    public function __construct(private readonly EvalContext $ctx)
    {
    }

    /** The descriptor, or the UnknownT of the gap that was just recorded. */
    public function describe(string $class, Node $at): ModelDescriptor|UnknownT
    {
        try {
            $descriptor = $this->ctx->models()->describe($class);
        } catch (ParseFailed $e) {
            return $this->ctx->gap(GapCode::PARSE_FAILED, $at, "could not parse the source of {$class}: {$e->getMessage()}");
        }

        return $descriptor ?? $this->ctx->gap(GapCode::MODEL_INFO_UNAVAILABLE, $at, "no model:show information for {$class}");
    }

    /** Like describe() but silent: for decisions that must not record anything (handles(), re-reading a known name). */
    public function peek(string $class): ?ModelDescriptor
    {
        try {
            return $this->ctx->models()->describe($class);
        } catch (ParseFailed) {
            return null;
        }
    }

    /** The table name; a model whose table is not known reads an unknown table (an assumption, the worst side). */
    public function table(ModelDescriptor $d, Node $at): ?string
    {
        if ($d->table === null) {
            $this->ctx->assume(AssumptionCode::TABLE_NAME_UNKNOWN, $at, "the table of {$d->fqcn} is not known");
        }

        return $d->table;
    }

    /** @return RelationInfo|null null when the model has no relation of that name (the caller decides what that means) */
    public function relation(ModelDescriptor $d, string $name, Node $at): ?RelationInfo
    {
        $relation = $d->relations[$name] ?? null;
        if ($relation !== null && $d->relationsSource === ModelDescriptor::SOURCE_INFERRED) {
            $this->ctx->assume(AssumptionCode::RELATION_INFERRED, $at, "relation {$d->fqcn}::{$name}() taken from an exported ancestor");
        }

        return $relation;
    }

    /**
     * The $with of a model. A $with that is not a constant list is a gap: it would add queries we cannot count.
     *
     * @return list<string>
     */
    public function withOf(ModelDescriptor $d, Node $at): array
    {
        if (in_array('with', $d->unresolved, true)) {
            $this->ctx->gap(GapCode::RELATION_TARGET_UNKNOWN, $at, "the \$with of {$d->fqcn} is not a constant list: the relations it loads are not known");
        }

        return $d->with;
    }

    /** True when the model has observers, so a write may fire hidden queries. */
    public function hasObservers(ModelDescriptor $d): bool
    {
        return $d->observers !== [];
    }
}
