<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Serialization\Support;

use PeakSim\Analysis\Contracts\CallHandler;
use PeakSim\Analysis\Contracts\CallSite;
use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Type\ObjectT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\Type;
use PhpParser\Node\Expr;

/**
 * The few plain helpers the test app uses, taken as safe: response(), count(), and the response object's
 * noContent(). It stands in for the real known-calls handler; json() is not here because the sink owns it.
 */
final class StubKnown implements CallHandler
{
    public const RESPONSE = 'Illuminate\Contracts\Routing\ResponseFactory';

    public function handles(CallSite $c, EvalContext $ctx): bool
    {
        if ($c->node instanceof Expr\FuncCall) {
            return in_array($c->name, ['response', 'count'], true);
        }

        return $c->node instanceof Expr\MethodCall
            && $c->receiver instanceof ObjectT
            && $c->receiver->class === self::RESPONSE
            && $c->name === 'noContent';
    }

    public function handle(CallSite $c, EvalContext $ctx): Type
    {
        foreach (array_keys($c->args) as $i) {
            $c->argType($i);
        }

        return $c->name === 'response' ? new ObjectT(self::RESPONSE) : new PlainT();
    }
}
