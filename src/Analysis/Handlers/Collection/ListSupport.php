<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Handlers\Collection;

use PeakSim\Analysis\Contracts\CallSite;
use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Type\ClosureT;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\ShapeT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Analysis\Type\UnknownT;
use PeakSim\Model\Cardinality;
use PeakSim\Model\CardinalityKind;
use PeakSim\Model\CostEvent;
use PeakSim\Model\Delivery;
use PeakSim\Model\EventKind;
use PeakSim\Model\Frame;
use PeakSim\Model\GapCode;
use PeakSim\Model\MemoryCause;
use PeakSim\Model\MemoryPart;
use PeakSim\Model\Origin;
use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * What the Collection and array handlers share: when running a callback over a list is a loop (a Frame), how a
 * stream is turned into rows held in memory, and how two list sizes combine. The size of a list (its Cardinality)
 * is carried through every step so a loop over a plucked, mapped or filtered list still knows which table it is long as.
 */
final class ListSupport
{
    /** The loop around a callback that runs once per element; null when the list is bounded, a window, or of unknown size. */
    public static function frameFor(ListT $list, EvalContext $ctx, Node $at): ?Frame
    {
        if ($list->window || $list->card->kind !== CardinalityKind::Table) {
            return null;
        }

        return new Frame($list->card->origin ?? new Origin(null), $list->delivery === Delivery::Streamed, $ctx->site($at));
    }

    /** The element a callback receives: a model keeps the table it came from, so a callee knows which rows it works on. */
    public static function elemOf(ListT $list): Type
    {
        $elem = $list->elem;
        if ($elem instanceof ModelT && $elem->origin === null && $list->card->origin !== null) {
            return $elem->withOrigin($list->card->origin);
        }

        return $elem;
    }

    /**
     * Run the callback in argument $index once, inside the loop that its being called per element makes.
     *
     * @param list<Type> $callbackArgs
     * @param bool $optional the method also takes a key or a plain value there (sortBy('total'), first())
     * @return Type|null the callback's return value; null when there was no callback to run (or a gap was recorded)
     */
    public static function runCallback(CallSite $c, int $index, array $callbackArgs, ListT $loopOver, EvalContext $ctx, bool $optional = false): ?Type
    {
        $node = $c->argNode($index);
        if ($node === null) {
            return null;
        }
        $callback = $c->argType($index);
        if ($callback instanceof UnknownT) {
            return $callback;
        }
        if (!$callback instanceof ClosureT) {
            if ($node instanceof Node\Scalar\String_) {
                return null; // a literal function name or key: nothing of the app runs
            }
            if ($optional && ($callback instanceof PlainT || $callback instanceof ListT || $callback instanceof ShapeT)) {
                return null; // a sort key or a list of them
            }

            return $ctx->gap(GapCode::UNRESOLVED_CALL, $node, 'the callback is not a closure, so what it does is not followed');
        }

        $frame = self::frameFor($loopOver, $ctx, $c->node);
        $run = static fn (): Type => $ctx->invokeClosure($callback, $callbackArgs);
        $returned = $frame !== null ? $ctx->withFrame($frame, $run) : $run();
        if ($frame === null && $loopOver->card->kind === CardinalityKind::Unknown && self::doesSomething($callback)) {
            $ctx->gap(GapCode::LOOP_SOURCE_UNKNOWN, $c->node, 'the callback runs once per element of a list whose size is not known, and it does something');
        }

        return $returned;
    }

    /** True when the closure body calls something or reads a property: otherwise running it once per element costs nothing. */
    public static function doesSomething(ClosureT $closure): bool
    {
        $found = (new NodeFinder())->findFirst(
            $closure->node instanceof Node\Expr\ArrowFunction ? [$closure->node->expr] : $closure->node->stmts,
            static fn (Node $n): bool => $n instanceof Node\Expr\CallLike || $n instanceof Node\Expr\PropertyFetch || $n instanceof Node\Expr\NullsafePropertyFetch,
        );

        return $found !== null;
    }

    /**
     * A stream (lazy / chunk window / cursor) turned into rows held at once. A list that is already held, or a window
     * (bounded by the chunk size), is returned as it is.
     */
    public static function materialize(ListT $list, EvalContext $ctx, Node $at): ListT
    {
        if ($list->window || $list->delivery === Delivery::Materialized) {
            return $list;
        }
        if ($list->card->kind === CardinalityKind::Table) {
            $origin = $list->card->origin;
            $ctx->emit(new CostEvent(
                EventKind::Payload,
                $ctx->site($at),
                [],
                null,
                new MemoryPart(1, $origin, MemoryCause::Materialize),
                $origin?->table,
                [],
                $ctx->seq(),
            ));
        }

        return $list->withDelivery(Delivery::Materialized);
    }

    /** The larger of two sizes: unknown over a table over bounded. */
    public static function worseCard(Cardinality $a, Cardinality $b): Cardinality
    {
        $rank = static fn (Cardinality $c): int => match ($c->kind) {
            CardinalityKind::Bounded => 1,
            CardinalityKind::Table => 2,
            CardinalityKind::Unknown => 3,
        };

        return $rank($b) > $rank($a) ? $b : $a;
    }

    /** A list made of lists (groups, chunks): the groups together are as long as the list was. */
    public static function listOfLists(ListT $inner, Cardinality $outer): ListT
    {
        return new ListT($inner, $outer, $inner->delivery, false);
    }

    /** What the elements of a list are when it is flattened one level. */
    public static function flatten(ListT $list): ListT
    {
        $elem = $list->elem;
        if ($elem instanceof ListT) {
            // Inner lists that grow with a table, inside an outer list that does, multiply: one Cardinality cannot say
            // how, so the size is declared unknown (a loop over it with something inside is a gap, not an undercount).
            $card = $elem->card->kind === CardinalityKind::Table && $list->card->kind !== CardinalityKind::Bounded
                ? Cardinality::unknown()
                : self::worseCard($list->card, $elem->card);

            return new ListT($elem->elem, $card, $list->delivery, false);
        }
        if ($elem instanceof ShapeT) {
            return $list->withElem(new PlainT());
        }

        return $list;
    }

    /** The common type of an array argument for the functions that take one: a list as it is, a literal as a bounded list. */
    public static function asList(Type $t): ?ListT
    {
        return match (true) {
            $t instanceof ListT => $t,
            $t instanceof ShapeT => new ListT($t->entries === [] ? new PlainT() : \PeakSim\Analysis\Engine\TypeMerge::joinAll(array_values($t->entries))->type, Cardinality::bounded()),
            default => null,
        };
    }
}
