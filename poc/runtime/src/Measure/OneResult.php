<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

use PeakSim\Runtime\Run\Attempt;

/** What a `bin/measure-one` process returned: its exit code, the attempts it printed, and its standard error. */
final class OneResult
{
    /** @param list<Attempt> $attempts */
    public function __construct(public readonly int $exit, public readonly array $attempts, public readonly string $stderr)
    {
    }

    /** @param list<Attempt> $attempts */
    public static function ok(array $attempts): self
    {
        return new self(0, $attempts, '');
    }
}
