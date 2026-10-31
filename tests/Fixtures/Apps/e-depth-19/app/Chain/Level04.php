<?php

namespace App\Chain;

class Level04
{
    public function __construct(private readonly Level05 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
