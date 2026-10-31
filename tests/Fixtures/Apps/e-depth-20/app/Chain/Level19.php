<?php

namespace App\Chain;

class Level19
{
    public function __construct(private readonly Level20 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
