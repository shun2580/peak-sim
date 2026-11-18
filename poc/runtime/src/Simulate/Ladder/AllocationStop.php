<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Ladder;

/** A stop of the route allocation (simulator-spec.md section 7.4.4): no prediction is made, and the exit code says which stop. */
final class AllocationStop extends \RuntimeException
{
    public function __construct(string $message, public readonly int $exitCode)
    {
        parent::__construct($message);
    }
}
