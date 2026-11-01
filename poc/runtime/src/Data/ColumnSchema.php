<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Data;

/** Structure of one column (never data). Built by SchemaReader from information_schema.COLUMNS. */
final class ColumnSchema
{
    /**
     * @param ?int         $length    CHARACTER_MAXIMUM_LENGTH (characters for text, bytes for binary)
     * @param list<string> $enumValues members of an ENUM or SET
     */
    public function __construct(
        public readonly string $name,
        public readonly string $dataType,
        public readonly string $columnType,
        public readonly ?int $length = null,
        public readonly ?int $precision = null,
        public readonly ?int $scale = null,
        public readonly bool $nullable = false,
        public readonly ?string $default = null,
        public readonly bool $hasDefault = false,
        public readonly bool $autoIncrement = false,
        public readonly bool $generated = false,
        public readonly bool $unsigned = false,
        public readonly array $enumValues = [],
    ) {
    }
}
