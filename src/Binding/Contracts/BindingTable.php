<?php

declare(strict_types=1);

namespace PeakSim\Binding;

interface BindingTable
{
    /** All candidate implementations of an abstract; the later binding does not win. */
    public function resolve(string $abstract, ?string $consumer = null): BindingResult;
}
