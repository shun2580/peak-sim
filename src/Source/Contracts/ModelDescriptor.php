<?php

declare(strict_types=1);

namespace PeakSim\Source;

use PeakSim\Input\RelationInfo;

/** What the tool knows about one model: the artisan export merged with the class source. */
final readonly class ModelDescriptor
{
    public const SOURCE_EXPORT = 'export';
    public const SOURCE_INFERRED = 'inferred';

    /**
     * @param array<string, RelationInfo> $relations
     * @param string $relationsSource export | inferred (inferred = taken from an ancestor, so RELATION_INFERRED applies)
     * @param list<string> $with the $with property, parents included
     * @param list<string> $appends the $appends property, parents included
     * @param array<string, string> $accessors appended or accessed name => accessor method name
     * @param list<string> $unresolved the properties ('with' | 'appends' | 'table') whose default was not a constant and was left out
     */
    public function __construct(
        public string $fqcn,
        public ?string $table,
        public ?string $driver,
        public array $relations,
        public string $relationsSource,
        public array $with,
        public array $appends,
        public array $accessors,
        public array $unresolved = [],
    ) {
    }
}
