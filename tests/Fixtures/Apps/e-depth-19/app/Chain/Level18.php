<?php

namespace App\Chain;

class Level18
{
    public function __construct(private readonly Level19 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
