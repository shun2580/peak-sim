<?php

namespace App\Chain;

class Level20
{
    public function __construct(private readonly Level21 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
