<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Handlers\Collection;

use PeakSim\Analysis\Contracts\CallHandler;
use PeakSim\Analysis\Contracts\CallSite;
use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Contracts\HandlerRegistry;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\ObjectT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\ShapeT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Model\CardinalityKind;
use PeakSim\Model\GapCode;
use PhpParser\Node\Expr;

/**
 * $orders->each->save(), $orders->map->customer: a Collection loop written as a property. Reading `each` off a list
 * gives a proxy; calling a method (or reading a property) on the proxy does that on every element, inside the loop.
 *
 * The proxy is a ShapeT with one entry, 'proxy:<name>', holding the list. What happens to one element is decided by
 * the handlers in the registry (the query handler knows what save() costs), so this handler asks them, with the
 * element as the receiver; an element no handler takes and that is not an app class is a gap.
 */
final class HigherOrderProxies implements CallHandler
{
    private const PREFIX = 'proxy:';

    /** Collection::$proxies. */
    private const NAMES = ['average', 'avg', 'contains', 'doesntcontain', 'each', 'every', 'filter', 'first', 'flatmap', 'groupby', 'keyby', 'map', 'max', 'min', 'partition', 'percentage', 'reject', 'skipuntil', 'skipwhile', 'some', 'sortby', 'sortbydesc', 'sum', 'takeuntil', 'takewhile', 'unique', 'unless', 'until', 'when'];

    private const SCALARS = ['average', 'avg', 'contains', 'doesntcontain', 'every', 'max', 'min', 'percentage', 'some', 'sum'];

    private const MATERIALIZES = ['sortby', 'sortbydesc', 'groupby', 'unique'];

    public function __construct(private readonly HandlerRegistry $registry)
    {
    }

    public function handles(CallSite $c, EvalContext $ctx): bool
    {
        $isProperty = $c->node instanceof Expr\PropertyFetch || $c->node instanceof Expr\NullsafePropertyFetch;
        if ($isProperty && $c->receiver instanceof ListT) {
            return in_array(strtolower($c->name), self::NAMES, true);
        }

        return ($isProperty || $c->node instanceof Expr\MethodCall || $c->node instanceof Expr\NullsafeMethodCall)
            && $this->proxied($c->receiver) !== null;
    }

    public function handle(CallSite $c, EvalContext $ctx): Type
    {
        if ($c->receiver instanceof ListT) {
            return new ShapeT([self::PREFIX . strtolower($c->name) => $c->receiver]);
        }
        [$kind, $list] = $this->proxied($c->receiver) ?? ['', null];
        assert($list instanceof ListT);
        if (in_array($kind, self::MATERIALIZES, true)) {
            $list = ListSupport::materialize($list, $ctx, $c->node);
        }

        $frame = ListSupport::frameFor($list, $ctx, $c->node);
        $elem = ListSupport::elemOf($list);
        $run = fn (): Type => $this->onElement($c, $elem, $ctx);
        $returned = $frame !== null ? $ctx->withFrame($frame, $run) : $run();
        if ($frame === null && $list->card->kind === CardinalityKind::Unknown) {
            $ctx->gap(GapCode::LOOP_SOURCE_UNKNOWN, $c->node, 'a call on every element of a list whose size is not known');
        }

        return match (true) {
            in_array($kind, self::SCALARS, true) => new PlainT(),
            $kind === 'map' => new ListT($returned, $list->card, $list->delivery, $list->window),
            $kind === 'flatmap' => ListSupport::flatten(new ListT($returned, $list->card, $list->delivery, $list->window)),
            $kind === 'first' => $elem,
            $kind === 'groupby' => ListSupport::listOfLists($list, $list->card),
            $kind === 'partition' => ListSupport::listOfLists($list, \PeakSim\Model\Cardinality::bounded()),
            default => $list,
        };
    }

    /** @return array{0: string, 1: ListT}|null the proxy's name and its list */
    private function proxied(?Type $receiver): ?array
    {
        if (!$receiver instanceof ShapeT || count($receiver->entries) !== 1) {
            return null;
        }
        $key = array_key_first($receiver->entries);
        $list = $receiver->entries[$key];

        return str_starts_with((string) $key, self::PREFIX) && $list instanceof ListT ? [substr((string) $key, strlen(self::PREFIX)), $list] : null;
    }

    /** What the call or property read costs on one element. */
    private function onElement(CallSite $c, Type $elem, EvalContext $ctx): Type
    {
        $isProperty = $c->node instanceof Expr\PropertyFetch || $c->node instanceof Expr\NullsafePropertyFetch;
        $inner = new CallSite($c->node, $elem, null, $c->name, $c->args, $isProperty ? null : fn (int $i): Type => $c->argType($i));
        foreach ($this->registry->handlers() as $handler) {
            if ($handler->handles($inner, $ctx)) {
                return $handler->handle($inner, $ctx);
            }
        }
        if (!$isProperty && ($elem instanceof ModelT || $elem instanceof ObjectT)) {
            $args = [];
            for ($i = 0; $c->argNode($i) !== null; $i++) {
                $args[] = $c->argType($i);
            }

            return $ctx->callMethod($elem->class, $c->name, $args, $c->node, $elem);
        }

        return $ctx->gap(GapCode::UNRESOLVED_CALL, $c->node, "{$c->name} on every element is not followed: the elements are not of a type the analysis knows");
    }
}
