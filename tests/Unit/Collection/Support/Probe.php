<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Collection\Support;

use PeakSim\Analysis\Contracts\CallHandler;
use PeakSim\Analysis\Contracts\CallSite;
use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Type\ClosureT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Model\Frame;
use PeakSim\Tests\Support\FakeEvalContext;
use PeakSim\Tests\Support\RecordingContext;

/**
 * A FakeEvalContext whose callbacks are recorded: each call of a closure notes the arguments it got and the loops
 * (frames) it ran inside. $returns gives what the closure "returns"; a plain value by default.
 */
final class Probe
{
    /** @var list<array{args: list<Type>, frames: list<Frame>}> */
    public array $invocations = [];

    public readonly FakeEvalContext $fake;

    public readonly RecordingContext $ctx;

    public function __construct(?Type $returns = null, ?FakeEvalContext $base = null)
    {
        $holder = new \stdClass();
        $holder->ctx = null;
        $this->fake = $base ?? new FakeEvalContext(
            closureInvoker: function (ClosureT $c, array $args) use ($holder, $returns): Type {
                $this->invocations[] = ['args' => $args, 'frames' => $holder->ctx->frames()];

                return $returns ?? new PlainT();
            },
        );
        $this->ctx = new RecordingContext($this->fake);
        $holder->ctx = $this->ctx;
    }

    public function run(CallHandler $handler, CallSite $site): Type
    {
        self::requireHandled($handler, $site, $this->ctx);

        return $this->ctx->handle($handler, $site);
    }

    private static function requireHandled(CallHandler $handler, CallSite $site, EvalContext $ctx): void
    {
        if (!$handler->handles($site, $ctx)) {
            throw new \LogicException('the handler does not take ' . $site->name . '()');
        }
    }
}
