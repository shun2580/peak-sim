<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Handlers\Collection;

use PeakSim\Analysis\Contracts\CallHandler;
use PeakSim\Analysis\Contracts\CallSite;
use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Engine\TypeMerge;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\ShapeT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Analysis\Type\UnknownT;
use PeakSim\Model\Cardinality;
use PeakSim\Model\GapCode;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;

/**
 * The PHP array functions that take a callback or carry a list's size through: array_map, usort, array_chunk, ...
 * A callback runs once per element, so it is a loop like a Collection's; the size of the array (which table it is
 * long as) is kept so that a loop over the result still knows what N is. count(), in_array() and the like cost
 * nothing and belong to the known-calls list, not here.
 */
final class ArrayFunctions implements CallHandler
{
    private const NAMES = [
        'collect', 'array_map', 'array_filter', 'array_reduce', 'array_walk', 'array_walk_recursive', 'usort', 'uasort', 'uksort',
        'array_chunk', 'array_slice', 'array_values', 'array_keys', 'array_merge', 'array_unique', 'array_reverse', 'array_column',
        'iterator_to_array', 'range',
    ];

    public function handles(CallSite $c, EvalContext $ctx): bool
    {
        return $c->node instanceof Expr\FuncCall && in_array(strtolower($c->name), self::NAMES, true);
    }

    public function handle(CallSite $c, EvalContext $ctx): Type
    {
        $plain = new PlainT();

        switch (strtolower($c->name)) {
            case 'collect':
                return $this->collect($c, $ctx);
            case 'range':
                return new ListT($plain, $this->isNumberLiteral($c->argNode(0)) && $this->isNumberLiteral($c->argNode(1)) ? Cardinality::bounded() : Cardinality::unknown());
            case 'array_merge':
                return $this->merge($c, $ctx);
            case 'array_map':
                return $this->map($c, $ctx);
        }

        $list = $this->listArg($c, 0, $ctx);
        if ($list instanceof UnknownT) {
            return $list;
        }
        $elem = ListSupport::elemOf($list);

        switch (strtolower($c->name)) {
            case 'array_filter':
                ListSupport::runCallback($c, 1, [$elem, $plain], $list, $ctx, true);

                return $list;
            case 'array_reduce':
                $carry = $c->argNode(2) !== null ? $c->argType(2) : $plain;
                $returned = ListSupport::runCallback($c, 1, [$carry, $elem], $list, $ctx);

                return $returned === null ? $carry : TypeMerge::joinAll([$carry, $returned])->type;
            case 'array_walk':
            case 'array_walk_recursive':
                ListSupport::runCallback($c, 1, [$elem, $plain], $list, $ctx);

                return $plain;
            case 'usort':
            case 'uasort':
            case 'uksort':
                ListSupport::runCallback($c, 1, [$elem, $elem], $list, $ctx);

                return $plain;
            case 'array_chunk':
                return ListSupport::listOfLists($list->withCard(Cardinality::bounded()), $list->card);
            case 'array_slice':
                $length = $c->argNode(2);
                $bounded = $length !== null && !($length instanceof Expr\ConstFetch && strtolower($length->name->toString()) === 'null');

                return $bounded ? $list->withCard(Cardinality::bounded()) : $list;
            case 'array_values':
            case 'array_reverse':
            case 'array_unique':
                return $list;
            case 'array_keys':
            case 'array_column':
                return new ListT($plain, $list->card, $list->delivery, $list->window);
            case 'iterator_to_array':
                return ListSupport::materialize($list, $ctx, $c->node);
        }

        return $ctx->gap(GapCode::UNRESOLVED_CALL, $c->node, "{$c->name}() is not modelled");
    }

    private function map(CallSite $c, EvalContext $ctx): Type
    {
        $plain = new PlainT();
        $first = $this->listArg($c, 1, $ctx);
        if ($first instanceof UnknownT) {
            return $first;
        }
        $args = [ListSupport::elemOf($first)];
        for ($i = 2; $c->argNode($i) !== null; $i++) {
            $other = $this->listArg($c, $i, $ctx);
            $args[] = $other instanceof UnknownT ? $other : ListSupport::elemOf($other);
        }
        $callback = $c->argNode(0);
        if ($callback instanceof Expr\ConstFetch && strtolower($callback->name->toString()) === 'null') {
            return new ListT($plain, $first->card, $first->delivery, $first->window); // array_map(null, ...) zips
        }
        $returned = ListSupport::runCallback($c, 0, $args, $first, $ctx);

        return new ListT($returned ?? $plain, $first->card, $first->delivery, $first->window);
    }

    private function merge(CallSite $c, EvalContext $ctx): Type
    {
        $elems = [];
        $card = Cardinality::bounded();
        $delivery = null;
        for ($i = 0; $c->argNode($i) !== null; $i++) {
            $part = $this->listArg($c, $i, $ctx, false);
            if ($part instanceof UnknownT) {
                return $part;
            }
            $elems[] = $part->elem;
            $card = ListSupport::worseCard($card, $part->card);
            $delivery ??= $part->delivery;
        }

        return new ListT($elems === [] ? new PlainT() : TypeMerge::joinAll($elems)->type, $card, $delivery ?? \PeakSim\Model\Delivery::Materialized);
    }

    private function collect(CallSite $c, EvalContext $ctx): Type
    {
        if ($c->argNode(0) === null) {
            return new ListT(new PlainT(), Cardinality::bounded());
        }
        $arg = $c->argType(0);

        return match (true) {
            $arg instanceof ListT => $arg,
            $arg instanceof ShapeT => ListSupport::asList($arg) ?? new ListT(new PlainT(), Cardinality::bounded()),
            $arg instanceof UnknownT => $arg,
            $arg instanceof ModelT => new ListT($arg, Cardinality::bounded()),
            default => new ListT(new PlainT(), Cardinality::unknown()),
        };
    }

    /**
     * Argument $i as a list: a list as it is, a literal as a bounded list, a plain value (an input array) as a list
     * of unknown size. Anything else is not an array: a gap.
     */
    private function listArg(CallSite $c, int $i, EvalContext $ctx, bool $strict = true): ListT|UnknownT
    {
        $node = $c->argNode($i);
        if ($node === null) {
            return new ListT(new PlainT(), Cardinality::bounded());
        }
        $type = $c->argType($i);
        $list = ListSupport::asList($type);
        if ($list !== null) {
            return $list;
        }
        if ($type instanceof UnknownT) {
            return $type;
        }
        if ($type instanceof PlainT) {
            return new ListT(new PlainT(), Cardinality::unknown());
        }

        return $ctx->gap(GapCode::UNRESOLVED_CALL, $node, ($strict ? $c->name . '() needs an array' : $c->name . '() got a value that is not an array') . ': what it holds is not known');
    }

    private function isNumberLiteral(?Node $n): bool
    {
        if ($n instanceof Expr\UnaryMinus || $n instanceof Expr\UnaryPlus) {
            $n = $n->expr;
        }

        return $n instanceof Scalar\Int_ || $n instanceof Scalar\Float_;
    }
}
