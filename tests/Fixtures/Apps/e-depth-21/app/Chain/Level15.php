<?php

namespace App\Chain;

class Level15
{
    public function __construct(private readonly Level16 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
