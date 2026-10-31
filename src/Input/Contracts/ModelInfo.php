<?php

declare(strict_types=1);

namespace PeakSim\Input;

/** What `model:show --json` says about one model. */
final readonly class ModelInfo
{
    /**
     * @param array<string, RelationInfo> $relations keyed by relation name
     * @param list<mixed> $attributes
     * @param list<mixed> $events
     * @param list<mixed> $observers
     */
    public function __construct(
        public string $class,
        public string $table,
        public string $database,
        public array $relations,
        public array $attributes,
        public array $events,
        public array $observers,
    ) {
    }
}
