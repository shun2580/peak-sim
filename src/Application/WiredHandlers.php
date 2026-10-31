<?php

declare(strict_types=1);

namespace PeakSim\Application;

use PeakSim\Analysis\Contracts\HandlerRegistry;
use PeakSim\Analysis\Engine\ActionReturnHook;

/** What HandlerWiring hands to the Engine: the handlers in order, and the hook at the end of each action. */
final class WiredHandlers
{
    public function __construct(
        public readonly HandlerRegistry $registry,
        public readonly ActionReturnHook $hook,
    ) {
    }
}
