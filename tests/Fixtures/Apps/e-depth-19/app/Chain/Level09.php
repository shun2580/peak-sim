<?php

namespace App\Chain;

class Level09
{
    public function __construct(private readonly Level10 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
