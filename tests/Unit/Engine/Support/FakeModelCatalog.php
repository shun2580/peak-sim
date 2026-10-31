<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Engine\Support;

use PeakSim\Input\RelationInfo;
use PeakSim\Source\ModelCatalog;
use PeakSim\Source\ModelDescriptor;

final class FakeModelCatalog implements ModelCatalog
{
    /** @var array<string, ModelDescriptor> */
    private array $models = [];

    /**
     * @param array<string, array{table: string, relations?: array<string, array{0: string, 1: bool}>, with?: list<string>}> $models
     */
    public function __construct(array $models)
    {
        foreach ($models as $class => $spec) {
            $relations = [];
            foreach ($spec['relations'] ?? [] as $name => [$related, $many]) {
                $relations[$name] = new RelationInfo($name, $many ? 'HasMany' : 'BelongsTo', $related, $many);
            }
            $this->models[$class] = new ModelDescriptor($class, $spec['table'], 'mysql', $relations, ModelDescriptor::SOURCE_EXPORT, $spec['with'] ?? [], [], []);
        }
    }

    public function isModel(string $fqcn): bool
    {
        return isset($this->models[ltrim($fqcn, '\\')]);
    }

    public function describe(string $fqcn): ?ModelDescriptor
    {
        return $this->models[ltrim($fqcn, '\\')] ?? null;
    }
}
