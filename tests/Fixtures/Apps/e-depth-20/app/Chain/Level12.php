<?php

namespace App\Chain;

class Level12
{
    public function __construct(private readonly Level13 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
