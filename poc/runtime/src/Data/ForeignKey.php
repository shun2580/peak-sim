<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Data;

final class ForeignKey
{
    /**
     * @param list<string> $columns    in key order
     * @param list<string> $refColumns same order as $columns
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly string $refTable,
        public readonly array $refColumns,
    ) {
    }
}
