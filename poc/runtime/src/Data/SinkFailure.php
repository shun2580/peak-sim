<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Data;

use RuntimeException;

/** A write the database refused, already sorted into a Reason code. */
final class SinkFailure extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
