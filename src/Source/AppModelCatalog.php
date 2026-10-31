<?php

declare(strict_types=1);

namespace PeakSim\Source;

use PeakSim\Input\ArtisanSource;
use PhpParser\Node;

/**
 * The models of the app: what `model:show` exports, merged with what only the class source says
 * ($table, $with, $appends, accessors).
 *
 * Models are found lazily by name; the app is not scanned. A model that is not in the export but has
 * an exported ancestor inherits that ancestor's relations, marked as inferred.
 */
final class AppModelCatalog implements ModelCatalog
{
    private const ELOQUENT_MODEL = 'Illuminate\Database\Eloquent\Model';
    private const ATTRIBUTE_CAST = 'Illuminate\Database\Eloquent\Casts\Attribute';
    private const MAX_DEPTH = 50;

    /** @var array<string, ModelDescriptor|null> */
    private array $descriptors = [];

    public function __construct(
        private readonly SourceIndex $source,
        private readonly ArtisanSource $artisan,
    ) {
    }

    /** @throws ParseFailed when the class, or one of its ancestors, cannot be parsed */
    public function isModel(string $fqcn): bool
    {
        $fqcn = AppSourceIndex::normalize($fqcn);
        if ($fqcn === self::ELOQUENT_MODEL) {
            return false;
        }
        $info = $this->source->findClass($fqcn);

        return $info !== null && $info->kind === 'class' && $this->source->extendsVendor($fqcn, self::ELOQUENT_MODEL);
    }

    /**
     * Null for a class that is not a model, and for a model that neither the export nor an exported
     * ancestor knows (isModel() tells the two apart: the caller raises MODEL_INFO_UNAVAILABLE).
     *
     * A property whose default is not a constant expression is left out and named in `unresolved`.
     * A caller that must not under-count (a `$with` adds queries) has to raise MODEL_PROPERTY_UNRESOLVED for it.
     *
     * @throws ParseFailed
     */
    public function describe(string $fqcn): ?ModelDescriptor
    {
        $fqcn = AppSourceIndex::normalize($fqcn);
        if (array_key_exists($fqcn, $this->descriptors)) {
            return $this->descriptors[$fqcn];
        }

        return $this->descriptors[$fqcn] = $this->build($fqcn);
    }

    private function build(string $fqcn): ?ModelDescriptor
    {
        if (!$this->isModel($fqcn)) {
            return null;
        }
        $chain = $this->chain($fqcn);

        $export = null;
        foreach ($chain as $class) {
            $export = $this->artisan->modelInfo($class);
            if ($export !== null) {
                break;
            }
        }
        if ($export === null) {
            return null;
        }

        $unresolved = [];
        $table = $export->class === $fqcn ? $export->table : ($this->declaredTable($chain, $unresolved) ?? $export->table);
        $with = $this->mergedList($chain, 'with', $unresolved);
        $appends = $this->mergedList($chain, 'appends', $unresolved);
        $unresolved = array_values(array_unique($unresolved));
        sort($unresolved, SORT_STRING);

        return new ModelDescriptor(
            $fqcn,
            $table,
            $export->database,
            $export->relations,
            $export->class === $fqcn ? ModelDescriptor::SOURCE_EXPORT : ModelDescriptor::SOURCE_INFERRED,
            $with,
            $appends,
            $this->accessors($chain),
            $unresolved,
            array_values(array_filter($export->observers, 'is_string')),
        );
    }

    /** @return list<string> the class first, then its parents that live in the app */
    private function chain(string $fqcn): array
    {
        $chain = [];
        for ($current = $fqcn; $current !== null && count($chain) < self::MAX_DEPTH; ) {
            $info = $this->source->findClass($current);
            if ($info === null || in_array($current, $chain, true)) {
                break;
            }
            $chain[] = $current;
            $current = $info->parent;
        }

        return $chain;
    }

    /**
     * The nearest `$table` written in the source; null when no class of the chain writes one.
     *
     * @param list<string> $chain
     * @param list<string> $unresolved
     */
    private function declaredTable(array $chain, array &$unresolved): ?string
    {
        foreach ($chain as $class) {
            $value = $this->propertyValue($class, 'table', $unresolved);
            if (is_string($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * A list property merged along the chain: ancestors first, then the class itself, without duplicates.
     *
     * @param list<string> $chain
     * @param list<string> $unresolved
     * @return list<string>
     */
    private function mergedList(array $chain, string $property, array &$unresolved): array
    {
        $merged = [];
        foreach (array_reverse($chain) as $class) {
            $value = $this->propertyValue($class, $property, $unresolved);
            foreach (is_array($value) ? $value : [] as $name) {
                if (is_string($name) && !in_array($name, $merged, true)) {
                    $merged[] = $name;
                }
            }
        }

        return $merged;
    }

    /**
     * @param list<string> $unresolved
     * @return mixed null when the class does not declare the property, or declares one that is not constant
     */
    private function propertyValue(string $class, string $property, array &$unresolved): mixed
    {
        $info = $this->source->findClass($class);
        if ($info === null) {
            return null;
        }
        $default = null;
        foreach ($info->node->getProperties() as $declaration) {
            foreach ($declaration->props as $item) {
                if ($item->name->toString() === $property) {
                    $default = $item->default;
                }
            }
        }
        if ($default === null) {
            return null;
        }
        $value = $this->source->evaluate($default, $class);
        if (!$value->known) {
            $unresolved[] = $property;

            return null;
        }

        return $value->value;
    }

    /**
     * Every accessor of the chain (and of the traits it uses), keyed by the attribute name it serves.
     * Nearest class wins.
     *
     * @param list<string> $chain
     * @return array<string, string>
     */
    private function accessors(array $chain): array
    {
        $accessors = [];
        foreach (array_reverse($chain) as $class) {
            foreach ($this->declaringClasses($class, []) as $holder) {
                $info = $this->source->findClass($holder);
                foreach ($info?->node->getMethods() ?? [] as $method) {
                    $attribute = $this->accessedAttribute($method);
                    if ($attribute !== null) {
                        $accessors[$attribute] = $method->name->toString();
                    }
                }
            }
        }
        ksort($accessors, SORT_STRING);

        return $accessors;
    }

    /**
     * The class and, below it, the traits it uses (nested), so a trait's accessors count for the model.
     * Traits come first so that a method of the class itself wins.
     *
     * @param list<string> $seen
     * @return list<string>
     */
    private function declaringClasses(string $class, array $seen): array
    {
        $info = $this->source->findClass($class);
        if ($info === null || in_array($class, $seen, true) || count($seen) >= self::MAX_DEPTH) {
            return [];
        }
        $seen[] = $class;
        $out = [];
        foreach ($info->traits as $trait) {
            array_push($out, ...$this->declaringClasses($trait, $seen));
        }
        $out[] = $class;

        return $out;
    }

    /** The attribute name when the method is an accessor (`getFooBarAttribute` or `fooBar(): Attribute`). */
    private function accessedAttribute(Node\Stmt\ClassMethod $method): ?string
    {
        if ($method->isStatic()) {
            return null;
        }
        $name = $method->name->toString();
        if (preg_match('/^get(.+)Attribute$/', $name, $m) === 1) {
            return self::snake($m[1]);
        }
        $returnType = $method->returnType;
        if ($returnType instanceof Node\Name && $returnType->toString() === self::ATTRIBUTE_CAST) {
            return self::snake($name);
        }

        return null;
    }

    private static function snake(string $name): string
    {
        return strtolower((string) preg_replace('/(.)(?=[A-Z])/u', '$1_', $name));
    }
}
