<?php

namespace App\Chain;

class Level13
{
    public function __construct(private readonly Level14 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
