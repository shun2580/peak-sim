<?php

namespace App\Chain;

class Level14
{
    public function __construct(private readonly Level15 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
