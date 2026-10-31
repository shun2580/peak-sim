<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Type;

/**
 * A value the analysis could not follow. Invariant I1: make one only through EvalContext::gap(),
 * which records the gap at the same time. Never `new UnknownT()` in a handler.
 */
final class UnknownT extends Type
{
    public function fingerprint(): string
    {
        return 'unknown';
    }
}
