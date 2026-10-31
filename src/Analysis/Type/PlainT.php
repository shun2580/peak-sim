<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Type;

/** A value known not to touch the DB: a number, a string, or the return value of a known call. */
final class PlainT extends Type
{
    public function fingerprint(): string
    {
        return 'plain';
    }
}
