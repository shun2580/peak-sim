<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Engine\Support;

use PeakSim\Analysis\Contracts\CallHandler;
use PeakSim\Analysis\Contracts\CallSite;
use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Type\ObjectT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\Type;
use PhpParser\Node\Expr;

/** response() and a few plain helpers, taken as safe. Not the real known-calls handler. */
final class StubKnownHandler implements CallHandler
{
    public const RESPONSE = 'Illuminate\Contracts\Routing\ResponseFactory';

    public function handles(CallSite $c, EvalContext $ctx): bool
    {
        return ($c->node instanceof Expr\FuncCall && in_array($c->name, ['response', 'round', 'count', 'now', 'in_array'], true))
            || ($c->node instanceof Expr\MethodCall && $c->receiver instanceof ObjectT && $c->receiver->class === self::RESPONSE);
    }

    public function handle(CallSite $c, EvalContext $ctx): Type
    {
        foreach ($c->args as $i => $_) {
            $c->argType($i);
        }

        return $c->name === 'response' ? new ObjectT(self::RESPONSE) : new PlainT();
    }
}
