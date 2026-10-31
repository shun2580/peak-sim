<?php

declare(strict_types=1);

namespace PeakSim\Prototype;

/**
 * Reads the pre-exported artisan JSON (route:list --json, model:show <Model> --json).
 * The prototype never runs artisan itself.
 */
final class ExportReader
{
    private const MANY_RELATIONS = ['HasMany', 'BelongsToMany', 'MorphMany', 'MorphToMany', 'HasManyThrough'];

    /** @var list<array<string, mixed>>|null */
    private ?array $routes = null;

    /** @var array<string, array{table: string, relations: array<string, array{related: string, many: bool}>}>|null */
    private ?array $models = null;

    public function __construct(private readonly string $exportDir)
    {
    }

    /** @return array<string, mixed>|null the route:list entry; $uri has no leading slash */
    public function findRoute(string $uri): ?array
    {
        if ($this->routes === null) {
            $this->routes = $this->readJson($this->exportDir . '/route-list.json');
        }
        foreach ($this->routes as $route) {
            if (($route['uri'] ?? null) === $uri) {
                return $route;
            }
        }

        return null;
    }

    public function isModel(string $class): bool
    {
        return $this->model($class) !== null;
    }

    /** @return array{table: string, relations: array<string, array{related: string, many: bool}>}|null */
    public function model(string $class): ?array
    {
        if ($this->models === null) {
            $this->models = [];
            $files = glob($this->exportDir . '/model-show/*.json') ?: [];
            sort($files);
            foreach ($files as $file) {
                $data = $this->readJson($file);
                $relations = [];
                foreach ($data['relations'] ?? [] as $relation) {
                    $type = basename(str_replace('\\', '/', (string) $relation['type']));
                    $relations[(string) $relation['name']] = [
                        'related' => (string) $relation['related'],
                        'many' => in_array($type, self::MANY_RELATIONS, true),
                    ];
                }
                $this->models[(string) $data['class']] = ['table' => (string) $data['table'], 'relations' => $relations];
            }
        }

        return $this->models[$class] ?? null;
    }

    public function table(string $class): ?string
    {
        return $this->model($class)['table'] ?? null;
    }

    /** @return array<mixed> */
    private function readJson(string $path): array
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException("cannot read export file: {$path}");
        }

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
