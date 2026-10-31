<?php

namespace App\Chain;

class Level05
{
    public function __construct(private readonly Level06 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
