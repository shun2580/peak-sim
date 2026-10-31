<?php

namespace App\Chain;

class Level11
{
    public function __construct(private readonly Level12 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
