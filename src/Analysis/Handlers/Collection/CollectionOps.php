<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Handlers\Collection;

use PeakSim\Analysis\Contracts\CallHandler;
use PeakSim\Analysis\Contracts\CallSite;
use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Engine\TypeMerge;
use PeakSim\Analysis\Handlers\Query\EagerLoads;
use PeakSim\Analysis\Handlers\Query\QueryKit;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\ShapeT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Model\Cardinality;
use PeakSim\Model\CardinalityKind;
use PeakSim\Model\CostEvent;
use PeakSim\Model\EventKind;
use PeakSim\Model\GapCode;
use PeakSim\Model\QueryPart;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;

/**
 * The methods of a Collection (or an array held as one): what comes back, which callbacks run once per element
 * (a loop: a Frame around the callback), how long the result is, and when a stream is held in memory.
 *
 * It takes only a call whose receiver is a ListT. The builder's where() and the collection's where() look alike;
 * the receiver's type tells them apart, so this handler never sees a BuilderT.
 *
 * A method it does not know may be a macro: that is a gap (UNKNOWN_COLLECTION_METHOD), never a guess.
 */
final class CollectionOps implements CallHandler
{
    /** Callback at argument 0 that must be a callback (a closure or a literal function name). */
    private const EACH = ['each', 'map', 'flatmap', 'mapwithkeys', 'maptogroups', 'reject', 'partition', 'takewhile', 'takeuntil', 'skipwhile', 'skipuntil'];

    /** Argument 0 may be a callback or a plain key / value (filter(), sortBy('total'), first(), contains(1)). */
    private const MAYBE_CALLBACK = ['filter', 'sortby', 'sortbydesc', 'groupby', 'keyby', 'unique', 'first', 'last', 'contains', 'doesntcontain', 'some', 'every', 'search', 'sum', 'avg', 'average', 'max', 'min', 'sort', 'count', 'firstwhere'];

    /** Stream into rows held at once. */
    private const MATERIALIZES = ['all', 'toarray', 'collect', 'sort', 'sortby', 'sortbydesc', 'sortkeys', 'sortkeysdesc', 'groupby', 'unique', 'reverse', 'shuffle', 'countby'];

    private const SAME_LIST = ['values', 'all', 'toarray', 'collect', 'tobase', 'lazy', 'filter', 'reject', 'where', 'wherein', 'wherenotin', 'wherenull', 'wherenotnull', 'wherebetween', 'wherenotbetween', 'whereinstanceof', 'wherestrict', 'whereinstrict', 'sort', 'sortby', 'sortbydesc', 'sortkeys', 'sortkeysdesc', 'reverse', 'shuffle', 'unique', 'uniquestrict', 'keyby', 'tap', 'takewhile', 'takeuntil', 'skipwhile', 'skipuntil', 'skip', 'except', 'only', 'diff', 'diffkeys', 'intersect', 'intersectbykeys', 'each', 'dump', 'dd', 'push', 'put', 'prepend', 'add', 'forget', 'flip'];

    private const BOUNDED = ['take', 'forpage', 'takelast'];

    private const ELEMENT = ['get', 'pop', 'shift', 'sole', 'firstorfail', 'pull', 'nth', 'find'];

    private const SCALAR = ['count', 'isempty', 'isnotempty', 'contains', 'doesntcontain', 'containsstrict', 'has', 'hasany', 'implode', 'join', 'tojson', 'json', 'sum', 'avg', 'average', 'median', 'mode', 'max', 'min', 'search', 'every', 'some', 'value', 'isassociative', 'containsoneitem', 'percentage'];

    private const LOAD_AGGREGATES = ['loadcount', 'loadsum', 'loadavg', 'loadmin', 'loadmax', 'loadexists'];

    public function handles(CallSite $c, EvalContext $ctx): bool
    {
        return ($c->node instanceof Expr\MethodCall || $c->node instanceof Expr\NullsafeMethodCall) && $c->receiver instanceof ListT;
    }

    public function handle(CallSite $c, EvalContext $ctx): Type
    {
        $list = $c->receiver;
        assert($list instanceof ListT);
        $name = strtolower($c->name);

        if (in_array($name, self::MATERIALIZES, true)) {
            $list = ListSupport::materialize($list, $ctx, $c->node);
        }
        $elem = ListSupport::elemOf($list);
        $plain = new PlainT();

        switch (true) {
            case $name === 'map':
                $returned = ListSupport::runCallback($c, 0, [$elem, $plain], $list, $ctx);

                return new ListT($returned ?? $plain, $list->card, $list->delivery, $list->window);
            case $name === 'flatmap':
                $returned = ListSupport::runCallback($c, 0, [$elem, $plain], $list, $ctx);

                return ListSupport::flatten(new ListT($returned ?? $plain, $list->card, $list->delivery, $list->window));
            case $name === 'mapwithkeys':
                $returned = ListSupport::runCallback($c, 0, [$elem, $plain], $list, $ctx);
                $value = $returned instanceof ShapeT ? TypeMerge::joinAll(array_values($returned->entries))->type : ($returned instanceof ListT ? $returned->elem : $plain);

                return new ListT($value, $list->card, $list->delivery, $list->window);
            case $name === 'maptogroups' || $name === 'groupby':
                ListSupport::runCallback($c, 0, [$elem, $plain], $list, $ctx, $name === 'groupby');

                return ListSupport::listOfLists($list, $list->card);
            case $name === 'partition':
                ListSupport::runCallback($c, 0, [$elem, $plain], $list, $ctx);

                return ListSupport::listOfLists($list, Cardinality::bounded());
            case in_array($name, ['chunk', 'split', 'splitin', 'sliding', 'zip'], true):
                return ListSupport::listOfLists($list->withCard(Cardinality::bounded()), $list->card);
            case $name === 'reduce':
                $carry = $c->argNode(1) !== null ? $c->argType(1) : $plain;
                $returned = ListSupport::runCallback($c, 0, [$carry, $elem], $list, $ctx);

                return $returned === null ? $carry : TypeMerge::joinAll([$carry, $returned])->type;
            case $name === 'tap':
                $this->once($c, $ctx, [$list]);

                return $list;
            case $name === 'pipe':
                return $this->once($c, $ctx, [$list]) ?? $list;
            case in_array($name, ['when', 'unless', 'whenempty', 'whennotempty', 'unlessempty', 'unlessnotempty'], true):
                $returned = $this->once($c, $ctx, [$list]);

                return $returned instanceof ListT ? TypeMerge::joinAll([$list, $returned])->type : $list;
            case in_array($name, ['load', 'loadmissing'], true):
                return $this->load($name === 'loadmissing', $list, $c, $ctx);
            case in_array($name, self::LOAD_AGGREGATES, true):
                return $this->loadAggregate($list, $c, $ctx);
            case $name === 'pluck':
                return new ListT($plain, $list->card, $list->delivery, $list->window);
            case $name === 'keys' || $name === 'countby':
                return new ListT($plain, $list->card, $list->delivery, $list->window);
            case $name === 'merge' || $name === 'concat' || $name === 'union':
                return $this->merge($list, $c);
            case $name === 'flatten' || $name === 'collapse':
                return ListSupport::flatten($list);
            case $name === 'random':
                return $c->argNode(0) === null ? $elem : $list->withCard(Cardinality::bounded());
            case $name === 'slice':
                return $c->argNode(1) !== null ? $list->withCard(Cardinality::bounded()) : $list;
            case in_array($name, self::BOUNDED, true):
                return $list->withCard(Cardinality::bounded());
            case in_array($name, ['first', 'last'], true):
                ListSupport::runCallback($c, 0, [$elem, $plain], $list, $ctx, true);

                return $elem;
            case in_array($name, self::ELEMENT, true) || $name === 'firstwhere':
                return $elem;
            case in_array($name, self::MAYBE_CALLBACK, true):
                $returned = ListSupport::runCallback($c, 0, $name === 'sort' ? [$elem, $elem] : [$elem, $plain], $list, $ctx, true);
                unset($returned);

                return in_array($name, self::SAME_LIST, true) ? $list : $plain;
            case in_array($name, self::EACH, true):
                $returned = ListSupport::runCallback($c, 0, [$elem, $plain], $list, $ctx);
                unset($returned);

                return $list; // each gives the list back; reject / takeWhile / ... keep a part of it, which is no longer
            case in_array($name, ['push', 'put', 'prepend', 'add'], true):
                // What is added may read the database; the engine counts the growth when the receiver is a variable.
                foreach (array_keys($c->args) as $i) {
                    $c->argType($i);
                }

                return $list;
            case in_array($name, self::SAME_LIST, true):
                return $list;
            case in_array($name, self::SCALAR, true):
                return $plain;
            default:
                return $ctx->gap(GapCode::UNKNOWN_COLLECTION_METHOD, $c->node, "{$c->name}() is not a known Collection method: it may be a macro, so what it does is not followed");
        }
    }

    /** A callback called once with the given arguments (tap, pipe, when): no loop. @param list<Type> $args */
    private function once(CallSite $c, EvalContext $ctx, array $args): ?Type
    {
        foreach ([0, 1] as $i) {
            if ($c->argNode($i) === null) {
                continue;
            }
            $callback = $c->argType($i);
            if ($callback instanceof \PeakSim\Analysis\Type\ClosureT) {
                return $ctx->invokeClosure($callback, $args);
            }
        }

        return null;
    }

    private function merge(ListT $list, CallSite $c): Type
    {
        $other = $c->argNode(0) === null ? null : ListSupport::asList($c->argType(0));
        if ($other === null) {
            return $list->withCard(ListSupport::worseCard($list->card, Cardinality::unknown()));
        }

        return new ListT(
            TypeMerge::joinAll([$list->elem, $other->elem])->type,
            ListSupport::worseCard($list->card, $other->card),
            $list->delivery,
            false,
        );
    }

    // ----- loading relations on the models of a list ---------------------------------------------------------

    private function load(bool $onlyMissing, ListT $list, CallSite $c, EvalContext $ctx): Type
    {
        $elem = $list->elem;
        if (!$elem instanceof ModelT) {
            $ctx->gap(GapCode::UNKNOWN_COLLECTION_METHOD, $c->node, "{$c->name}() on a list that is not made of models");

            return $list;
        }
        $paths = $this->relationPaths($c, $ctx);
        if ($paths === null) {
            return $list;
        }
        $kit = new QueryKit($ctx);
        $names = $kit->eager->expand($elem->class, $paths, $c->node);
        if ($onlyMissing) {
            $names = array_values(array_filter($names, static fn (string $n): bool => !in_array($n, $elem->eager, true)));
        }
        $parent = $list->window || $list->card->kind === CardinalityKind::Bounded ? EagerLoads::BOUNDED : EagerLoads::MANY;
        $kit->eager->emit($elem->class, $names, $c->node, $parent);

        return $list->withElem($elem->withEager($names));
    }

    private function loadAggregate(ListT $list, CallSite $c, EvalContext $ctx): Type
    {
        $elem = $list->elem;
        if (!$elem instanceof ModelT) {
            $ctx->gap(GapCode::UNKNOWN_COLLECTION_METHOD, $c->node, "{$c->name}() on a list that is not made of models");

            return $list;
        }
        $table = (new QueryKit($ctx))->facts->peek($elem->class)?->table;
        $ctx->emit(new CostEvent(EventKind::Aggregate, $ctx->site($c->node), [], new QueryPart(), null, $table, [], $ctx->seq()));

        return $list;
    }

    /**
     * The relation names written in load('a', 'b.c') or load(['a', 'b' => fn ...]).
     *
     * @return list<string>|null null after a gap: a name that is not a literal
     */
    private function relationPaths(CallSite $c, EvalContext $ctx): ?array
    {
        $paths = [];
        for ($i = 0; ($node = $c->argNode($i)) !== null; $i++) {
            $items = $node instanceof Expr\Array_ ? array_map(static fn (?Expr\ArrayItem $item): ?Expr => $item === null ? null : ($item->key ?? $item->value), $node->items) : [$node];
            foreach ($items as $item) {
                if (!$item instanceof Scalar\String_) {
                    $ctx->gap(GapCode::UNRESOLVED_CALL, $item ?? $node, "the relation names of {$c->name}() are not literal strings: which relations are loaded is not known");

                    return null;
                }
                $paths[] = $item->value;
            }
        }

        return $paths;
    }
}
