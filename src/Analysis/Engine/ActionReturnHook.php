<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Engine;

use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Type\Type;
use PhpParser\Node;

/**
 * Called once when the route's action (level 1) has been walked, with the value it returns. This is where the
 * framework serialises the response, so the serialisation sink (resources, $appends, toArray) plugs in here.
 * Events and gaps the hook records land in the action's own summary.
 */
interface ActionReturnHook
{
    public function onActionReturn(Type $returned, Node $action, EvalContext $ctx): void;
}
