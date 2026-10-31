<?php

namespace App\Concerns;

trait HasSubtitle
{
    protected function subtitle(): string
    {
        return 'all';
    }
}
