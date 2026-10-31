<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Known;

use PeakSim\Analysis\Contracts\CallHandler;
use PeakSim\Analysis\Contracts\CallSite;
use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Type\ObjectT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\Type;
use PhpParser\Node\Expr;

/**
 * What the serialisation owner's ResponseSink does with `response()->json($x)` as far as these tests care: it takes
 * the call (the allow-list declines it) and evaluates the argument. Not the real sink.
 */
final class StubSink implements CallHandler
{
    public function handles(CallSite $c, EvalContext $ctx): bool
    {
        return $c->node instanceof Expr\MethodCall
            && $c->receiver instanceof ObjectT
            && $c->receiver->class === KnownCase::RESPONSE_FACTORY
            && $c->name === 'json';
    }

    public function handle(CallSite $c, EvalContext $ctx): Type
    {
        foreach ($c->args as $i => $_) {
            $c->argType($i);
        }

        return new PlainT();
    }
}
