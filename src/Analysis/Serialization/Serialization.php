<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Serialization;

use PeakSim\Analysis\Contracts\CallHandler;
use PeakSim\Analysis\Engine\ActionReturnHook;

/**
 * The serialisation handlers and the hook that go together, sharing one Serializer (its cycle guard must see the
 * whole nesting). The caller registers the handlers and gives the hook to the Engine.
 *
 * Register the handlers BEFORE the Collection handlers: the sink serialises the rows of `$models->toArray()` and
 * then hands the call on to the Collection handler for its normal meaning.
 */
final class Serialization
{
    /** @param list<CallHandler> $handlers in the order they should be registered */
    public function __construct(
        public readonly array $handlers,
        public readonly ActionReturnHook $hook,
    ) {
    }

    public static function create(): self
    {
        $serializer = new Serializer();
        $sink = new ResponseSink($serializer);

        return new self([$sink, new ResourceHandler($serializer), new AttributeCasts()], $sink);
    }
}
