<?php

declare(strict_types=1);

namespace PeakSim\Prototype;

/**
 * The few shapes the simple type inference tracks: an Eloquent model, a query builder for a model,
 * a collection of models, or a plain object of an app class (a constructor-injected dependency).
 * Anything else is "unknown" and is represented by null.
 */
final class Type
{
    public const MODEL = 'model';
    public const BUILDER = 'builder';
    public const COLLECTION = 'collection';
    public const OBJECT = 'object';

    /** @param list<string> $eager relation names already loaded with with(); dotted names are nested loads */
    private function __construct(
        public readonly string $kind,
        public readonly ?string $class = null,
        public readonly array $eager = [],
        public readonly ?Type $elem = null,
    ) {
    }

    /** @param list<string> $eager */
    public static function model(string $class, array $eager = []): self
    {
        return new self(self::MODEL, $class, $eager);
    }

    /** @param list<string> $eager */
    public static function builder(string $class, array $eager = []): self
    {
        return new self(self::BUILDER, $class, $eager);
    }

    public static function collection(self $elem): self
    {
        return new self(self::COLLECTION, null, [], $elem);
    }

    public static function object(string $class): self
    {
        return new self(self::OBJECT, $class);
    }

    /** @param list<string> $names */
    public function withEager(array $names): self
    {
        return new self($this->kind, $this->class, array_values(array_unique([...$this->eager, ...$names])), $this->elem);
    }
}
