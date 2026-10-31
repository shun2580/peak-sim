<?php

namespace App\Models\Concerns;

trait HasShortCode
{
    public function getShortCodeAttribute(): string
    {
        return 'oo';
    }
}
