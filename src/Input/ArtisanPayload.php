<?php

declare(strict_types=1);

namespace PeakSim\Input;

/**
 * Turns decoded `route:list --json` / `model:show --json` output into value objects. Both input modes use it, so
 * they cannot disagree about what a Laravel 11, 12 or 13 payload means. Keys that only some versions print
 * (`path`, `collection`, `builder`, `resource`) are read when present and ignored when not.
 *
 * Shape problems throw \UnexpectedValueException; the callers turn that into an ArtisanOutputNotJsonException that
 * says where the payload came from.
 *
 * @internal
 */
final class ArtisanPayload
{
    /** Relation types (short class names) whose result is a collection. */
    private const MANY_RELATIONS = [
        'HasMany', 'BelongsToMany', 'HasManyThrough', 'MorphMany', 'MorphToMany', 'MorphedByMany',
    ];

    /** Strict JSON: no leading text, no trailing text, no byte order mark. @throws \JsonException */
    public static function decode(string $json): mixed
    {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<RouteEntry> sorted by uri, which keeps its leading slash
     *
     * @throws \UnexpectedValueException
     */
    public static function routes(mixed $data): array
    {
        if (!is_array($data) || !array_is_list($data)) {
            throw new \UnexpectedValueException('expected a JSON list of routes');
        }

        $routes = [];
        foreach ($data as $index => $row) {
            if (!is_array($row) || !is_string($row['uri'] ?? null) || !is_string($row['method'] ?? null) || !is_string($row['action'] ?? null)) {
                throw new \UnexpectedValueException("route #{$index} lacks a string uri, method or action");
            }
            $routes[] = new RouteEntry(
                '/' . ltrim($row['uri'], '/'),
                array_values(array_filter(explode('|', $row['method']), static fn (string $m): bool => $m !== '')),
                self::nonEmptyString($row['name'] ?? null),
                $row['action'],
                self::strings($row['middleware'] ?? []),
                self::nonEmptyString($row['path'] ?? null),
                self::nonEmptyString($row['domain'] ?? null),
            );
        }

        usort($routes, static fn (RouteEntry $a, RouteEntry $b): int => [$a->uri, implode('|', $a->methods)] <=> [$b->uri, implode('|', $b->methods)]);

        return $routes;
    }

    /** @throws \UnexpectedValueException */
    public static function model(mixed $data): ModelInfo
    {
        if (!is_array($data) || array_is_list($data) || !is_string($data['class'] ?? null) || $data['class'] === '') {
            throw new \UnexpectedValueException('expected a JSON object with a "class"');
        }
        if (!is_string($data['table'] ?? null)) {
            throw new \UnexpectedValueException("model {$data['class']} lacks a string table");
        }

        $relations = [];
        foreach (is_array($data['relations'] ?? null) ? $data['relations'] : [] as $relation) {
            if (!is_array($relation) || !is_string($relation['name'] ?? null) || !is_string($relation['type'] ?? null)) {
                throw new \UnexpectedValueException("a relation of {$data['class']} lacks a string name or type");
            }
            $type = basename(str_replace('\\', '/', $relation['type']));
            $related = $relation['related'] ?? null;
            $relations[$relation['name']] = new RelationInfo(
                $relation['name'],
                $type,
                is_string($related) && $related !== '' ? ltrim($related, '\\') : null,
                in_array($type, self::MANY_RELATIONS, true),
            );
        }

        return new ModelInfo(
            ltrim($data['class'], '\\'),
            $data['table'],
            is_string($data['database'] ?? null) ? $data['database'] : '',
            $relations,
            self::list($data['attributes'] ?? []),
            self::list($data['events'] ?? []),
            self::list($data['observers'] ?? []),
        );
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (is_string($value)) {
            return $value === '' ? [] : [$value];
        }

        return is_array($value) ? array_values(array_map('strval', array_filter($value, 'is_scalar'))) : [];
    }

    /** @return list<mixed> */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
