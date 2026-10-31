<?php

namespace App\Chain;

class Level08
{
    public function __construct(private readonly Level09 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
