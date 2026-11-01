<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Run;

/** The class of one attempt and the short reason shown next to it. */
final class ClassificationResult
{
    public function __construct(
        public readonly string $class,
        public readonly ?string $detail = null,
    ) {
    }

    /** Only an `ok` attempt may feed a verdict; every other class is a recorded reason. */
    public function isMeasured(): bool
    {
        return Classification::isMeasured($this->class);
    }
}
