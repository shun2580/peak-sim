<?php

namespace App\Chain;

class Level06
{
    public function __construct(private readonly Level07 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
