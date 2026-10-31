<?php

declare(strict_types=1);

namespace PeakSim\Input;

/** One relation of `model:show --json`. */
final readonly class RelationInfo
{
    public function __construct(
        public string $name,
        public string $type,
        public ?string $related,
        public bool $many,
    ) {
    }
}
