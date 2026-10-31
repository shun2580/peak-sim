<?php

namespace App\Chain;

class Level17
{
    public function __construct(private readonly Level18 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
