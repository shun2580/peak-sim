<?php

declare(strict_types=1);

namespace PeakSim\Model;

/** Where an N comes from. A null table means the table name is not known. */
final readonly class Origin
{
    public function __construct(
        public ?string $table,
        /** For an M: the table whose single row this M is "per" (e.g. items per orders). */
        public ?string $perTable = null,
    ) {
    }

    public function fingerprint(): string
    {
        return ($this->table ?? '?') . ($this->perTable === null ? '' : '/' . $this->perTable);
    }
}
