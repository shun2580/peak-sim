<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Type;

/** A query builder for a model class (or a bare table). $relationOf is [model class, relation name] for $model->relation(). */
final class BuilderT extends Type
{
    /** @var list<string> */
    public readonly array $eager;

    /**
     * @param list<string> $eager
     * @param array{0: string, 1: string}|null $relationOf
     */
    public function __construct(
        public readonly ?string $class,
        public readonly ?string $table = null,
        array $eager = [],
        public readonly bool $bounded = false,
        public readonly ?array $relationOf = null,
    ) {
        $this->eager = self::normalizeNames($eager);
    }

    /** @param list<string> $names */
    public function withEager(array $names): self
    {
        return new self($this->class, $this->table, [...$this->eager, ...$names], $this->bounded, $this->relationOf);
    }

    public function withBounded(bool $bounded = true): self
    {
        return new self($this->class, $this->table, $this->eager, $bounded, $this->relationOf);
    }

    /** @param array{0: string, 1: string}|null $relationOf */
    public function withRelationOf(?array $relationOf): self
    {
        return new self($this->class, $this->table, $this->eager, $this->bounded, $relationOf);
    }

    public function fingerprint(): string
    {
        return 'builder:' . ($this->class ?? '-') . '/' . ($this->table ?? '-') . '[' . implode(',', $this->eager) . ']'
            . ($this->bounded ? ':bounded' : '')
            . ($this->relationOf === null ? '' : ':rel(' . $this->relationOf[0] . '::' . $this->relationOf[1] . ')');
    }
}
