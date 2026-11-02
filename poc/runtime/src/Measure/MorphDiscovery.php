<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

use PeakSim\Runtime\Data\MorphLink;

/**
 * Reads the polymorphic relations a started app declares, from the app itself, so that nothing about one app is written
 * into the tool: for each model class, every public method that takes no argument and is declared to return a
 * `MorphMany`, `MorphOne` or `MorphToMany` is called (it only builds the relation object; no query is sent), and the
 * relation says the child table, its id column and its type column, and the parent model says its table, key and the
 * value it stores in a type column.
 *
 * It is read by the shape of the answers (method names, return type names), not by the framework's classes, so it
 * can be tested without the framework. A class that is missing, a method that throws and a relation that does not
 * answer are skipped, one by one; they never stop the others.
 */
final class MorphDiscovery
{
    private const RELATION_TYPES = ['MorphMany', 'MorphOne', 'MorphToMany'];

    /**
     * @param iterable<string> $classes model class names
     *
     * @return list<MorphLink> sorted by child table, column and parent, without duplicates
     */
    public static function discover(iterable $classes): array
    {
        $found = [];
        foreach ($classes as $class) {
            foreach (self::linksOf((string) $class) as $link) {
                $found[implode("\0", [$link->table, $link->idColumn, $link->typeColumn, $link->parentTable, $link->typeValue])] = $link;
            }
        }
        ksort($found, SORT_STRING);

        return array_values($found);
    }

    /**
     * The class names of the `.php` files under a directory, named from the paths below it (PSR-4).
     *
     * @return list<string> sorted
     */
    public static function classNamesIn(string $dir, string $namespacePrefix): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $names = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php' || count($names) >= 5000) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen(rtrim($dir, '/')) + 1, -4);
            $names[] = $namespacePrefix . str_replace('/', '\\', $relative);
        }
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * The model classes of a started app: the files of its `app/Models` (or `app` when there is no such directory),
     * those that are Eloquent models.
     *
     * @param object $app Illuminate\Foundation\Application
     *
     * @return list<string>
     */
    public static function appModels(object $app): array
    {
        $model = 'Illuminate\\Database\\Eloquent\\Model';
        $namespace = method_exists($app, 'getNamespace') ? (string) $app->getNamespace() : 'App\\';
        $base = method_exists($app, 'path') ? (string) $app->path() : '';
        $dir = is_dir($base . '/Models') ? $base . '/Models' : $base;
        $prefix = $namespace . (is_dir($base . '/Models') ? 'Models\\' : '');

        $models = [];
        foreach (self::classNamesIn($dir, $prefix) as $class) {
            try {
                if (class_exists($class) && is_subclass_of($class, $model) && !(new \ReflectionClass($class))->isAbstract()) {
                    $models[] = $class;
                }
            } catch (\Throwable) {
                // a file that cannot be loaded is not a model the tool can ask
            }
        }

        return $models;
    }

    /** @return list<MorphLink> */
    private static function linksOf(string $class): array
    {
        try {
            if (!class_exists($class)) {
                return [];
            }
            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || !$reflection->hasMethod('getTable') || !$reflection->hasMethod('getMorphClass')) {
                return [];
            }
            $parent = $reflection->newInstance();
            $parentTable = (string) $parent->getTable();
            $parentMorph = (string) $parent->getMorphClass();
            $parentKey = method_exists($parent, 'getKeyName') ? (string) $parent->getKeyName() : 'id';
        } catch (\Throwable) {
            return [];
        }

        $links = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isAbstract() || $method->getNumberOfRequiredParameters() > 0 || $method->getNumberOfParameters() > 0) {
                continue;
            }
            $type = $method->getReturnType();
            $name = $type instanceof \ReflectionNamedType ? $type->getName() : '';
            $short = substr($name, (int) strrpos('\\' . $name, '\\'));
            if (!in_array($short, self::RELATION_TYPES, true)) {
                continue;
            }
            try {
                $relation = $method->invoke($parent);
                $link = self::linkOf($relation, $short, $parentTable, $parentKey, $parentMorph);
            } catch (\Throwable) {
                continue;
            }
            if ($link !== null) {
                $links[] = $link;
            }
        }

        return $links;
    }

    private static function linkOf(mixed $relation, string $kind, string $parentTable, string $parentKey, string $parentMorph): ?MorphLink
    {
        if (!is_object($relation) || !method_exists($relation, 'getMorphType')) {
            return null;
        }
        if ($kind === 'MorphToMany') {
            $table = (string) $relation->getTable();
            $id = (string) $relation->getForeignPivotKeyName();
            $key = method_exists($relation, 'getParentKeyName') ? self::bare((string) $relation->getParentKeyName()) : $parentKey;
        } else {
            $table = (string) $relation->getRelated()->getTable();
            $id = (string) $relation->getForeignKeyName();
            $key = method_exists($relation, 'getLocalKeyName') ? self::bare((string) $relation->getLocalKeyName()) : $parentKey;
        }
        $type = self::bare((string) $relation->getMorphType());
        $id = self::bare($id);
        if ($table === '' || $id === '' || $type === '' || $key === '') {
            return null;
        }

        return new MorphLink($table, $id, $type, $parentMorph, $parentTable, $key);
    }

    /** `table.column` becomes `column`. */
    private static function bare(string $name): string
    {
        $dot = strrpos($name, '.');

        return $dot === false ? $name : substr($name, $dot + 1);
    }
}
