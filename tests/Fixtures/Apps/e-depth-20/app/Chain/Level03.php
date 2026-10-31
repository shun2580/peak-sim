<?php

namespace App\Chain;

class Level03
{
    public function __construct(private readonly Level04 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
