<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Engine;

use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\ObjectT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Model\Cardinality;
use PeakSim\Source\ModelCatalog;
use PhpParser\Node;

/** Turns a declared type (a parameter, a return type, a property type) into a Type, or null when it says nothing. */
final class TypeResolver
{
    private const SCALARS = ['int', 'float', 'string', 'bool', 'null', 'void', 'never', 'false', 'true'];

    private const COLLECTION_CLASSES = [
        'Illuminate\Support\Collection',
        'Illuminate\Database\Eloquent\Collection',
    ];

    public function __construct(
        private readonly ModelCatalog $models,
    ) {
    }

    public function fromDeclaration(?Node $type, ?string $class, ?string $declClass, ?\Closure $parentOf = null): ?Type
    {
        if ($type instanceof Node\NullableType) {
            return $this->fromDeclaration($type->type, $class, $declClass, $parentOf);
        }
        if ($type instanceof Node\UnionType) {
            $parts = array_values(array_filter(
                $type->types,
                static fn (Node $t): bool => !($t instanceof Node\Identifier && strtolower($t->toString()) === 'null'),
            ));

            return count($parts) === 1 ? $this->fromDeclaration($parts[0], $class, $declClass, $parentOf) : $this->allScalar($parts);
        }
        if ($type instanceof Node\Identifier) {
            return $this->fromName($type->toString(), $class, $declClass, $parentOf);
        }
        if ($type instanceof Node\Name) {
            return $this->fromName($type->toString(), $class, $declClass, $parentOf);
        }

        return null;
    }

    /** A type written as text, as SourceIndex::propertyType() returns it. */
    public function fromTypeString(?string $text, ?string $class = null): ?Type
    {
        if ($text === null || $text === '') {
            return null;
        }
        $parts = array_values(array_filter(
            explode('|', ltrim($text, '?')),
            static fn (string $p): bool => strtolower($p) !== 'null',
        ));
        if (count($parts) !== 1) {
            return null;
        }

        return $this->fromName($parts[0], $class, $class);
    }

    public function forClass(string $class): Type
    {
        $class = ltrim($class, '\\');
        if (in_array($class, self::COLLECTION_CLASSES, true)) {
            return new ListT(new PlainT(), Cardinality::unknown());
        }

        return $this->models->isModel($class) ? new ModelT($class) : new ObjectT($class);
    }

    private function fromName(string $name, ?string $class, ?string $declClass, ?\Closure $parentOf = null): ?Type
    {
        $lower = strtolower($name);
        if (in_array($lower, self::SCALARS, true)) {
            return new PlainT();
        }

        return match ($lower) {
            'array', 'iterable' => new ListT(new PlainT(), Cardinality::unknown()),
            'mixed', 'object', 'callable', 'closure' => null,
            'self', 'static' => $class === null ? null : $this->forClass($class),
            'parent' => ($declClass !== null && $parentOf !== null && ($p = $parentOf($declClass)) !== null) ? $this->forClass($p) : null,
            default => $this->forClass($name),
        };
    }

    /** @param list<Node> $parts */
    private function allScalar(array $parts): ?Type
    {
        foreach ($parts as $part) {
            if (!($part instanceof Node\Identifier && in_array(strtolower($part->toString()), self::SCALARS, true))) {
                return null;
            }
        }

        return $parts === [] ? null : new PlainT();
    }
}
