<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Engine;

use PeakSim\Analysis\Type\Type;

final readonly class JoinResult
{
    public function __construct(
        public Type $type,
        /** True when the joined type is less precise than a branch (the worse side was adopted). */
        public bool $lossy,
    ) {
    }
}
