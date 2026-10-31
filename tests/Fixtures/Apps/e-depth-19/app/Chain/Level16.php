<?php

namespace App\Chain;

class Level16
{
    public function __construct(private readonly Level17 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
