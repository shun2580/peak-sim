<?php

namespace App\Chain;

class Level10
{
    public function __construct(private readonly Level11 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
