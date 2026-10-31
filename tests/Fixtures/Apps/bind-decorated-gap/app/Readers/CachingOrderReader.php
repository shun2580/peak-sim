<?php

namespace App\Readers;

use App\Contracts\OrderReader;

class CachingOrderReader implements OrderReader
{
    public function __construct(private readonly OrderReader $inner)
    {
    }

    public function names(): array
    {
        return $this->inner->names();
    }
}
