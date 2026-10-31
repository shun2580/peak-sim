<?php

namespace App\Chain;

class Level02
{
    public function __construct(private readonly Level03 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
