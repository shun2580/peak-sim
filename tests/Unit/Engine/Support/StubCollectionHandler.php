<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Engine\Support;

use PeakSim\Analysis\Contracts\CallHandler;
use PeakSim\Analysis\Contracts\CallSite;
use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Type\ClosureT;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Model\CardinalityKind;
use PeakSim\Model\Delivery;
use PeakSim\Model\Frame;
use PhpParser\Node\Expr;

/** each / map / filter on a list: the callback runs once per element, so a table-sized list makes a frame. Not the real handler. */
final class StubCollectionHandler implements CallHandler
{
    public function handles(CallSite $c, EvalContext $ctx): bool
    {
        return $c->node instanceof Expr\MethodCall && $c->receiver instanceof ListT && in_array($c->name, ['each', 'map', 'filter'], true);
    }

    public function handle(CallSite $c, EvalContext $ctx): Type
    {
        $list = $c->receiver;
        assert($list instanceof ListT);
        $callback = $c->argType(0);
        if (!$callback instanceof ClosureT) {
            return new PlainT();
        }
        $run = static fn (): Type => $ctx->invokeClosure($callback, [$list->elem]);
        $returned = $list->card->kind === CardinalityKind::Table && $list->card->origin !== null && !$list->window
            ? $ctx->withFrame(new Frame($list->card->origin, $list->delivery === Delivery::Streamed, $ctx->site($c->node)), $run)
            : $run();

        return match ($c->name) {
            'map' => new ListT($returned, $list->card, $list->delivery),
            'filter' => $list,
            default => new PlainT(),
        };
    }
}
