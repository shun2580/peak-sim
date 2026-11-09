<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Load;

/** A target's `observed` was asked for before the outputs it must not come before were written (section 3.4). A programming error, not an input error. */
final class OrderViolation extends \LogicException
{
}
