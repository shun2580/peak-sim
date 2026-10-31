<?php

namespace App\Chain;

class Level07
{
    public function __construct(private readonly Level08 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
