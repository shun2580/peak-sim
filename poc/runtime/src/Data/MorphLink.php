<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Data;

/**
 * One polymorphic relation as the app declares it: the rows of `$table` belong to a row of `$parentTable` when
 * `$typeColumn` holds `$typeValue` (the morph class or its alias) and `$idColumn` holds the parent's `$parentKey`. The
 * database has no foreign key for it, so the generator is told: without it the type is a made-up word and the id a
 * number, and no generated row belongs to anything.
 *
 * A to-many relation (`morphToMany`) has a second key on the same table that points at the related model, and that key
 * has no foreign key either. When the relation says so, `$relatedColumn` is that key and `$relatedTable` / `$relatedKey`
 * the table and column it points at; all three are null for a relation that does not say (or has no other side).
 */
final class MorphLink
{
    public function __construct(
        public readonly string $table,
        public readonly string $idColumn,
        public readonly string $typeColumn,
        public readonly string $typeValue,
        public readonly string $parentTable,
        public readonly string $parentKey,
        public readonly ?string $relatedColumn = null,
        public readonly ?string $relatedTable = null,
        public readonly ?string $relatedKey = null,
    ) {
    }
}
