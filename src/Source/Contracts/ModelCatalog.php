<?php

declare(strict_types=1);

namespace PeakSim\Source;

interface ModelCatalog
{
    /** True when the ancestry reaches Illuminate\Database\Eloquent\Model. */
    public function isModel(string $fqcn): bool;

    public function describe(string $fqcn): ?ModelDescriptor;
}
