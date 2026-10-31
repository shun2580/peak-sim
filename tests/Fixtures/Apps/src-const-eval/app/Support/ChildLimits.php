<?php

namespace App\Support;

class ChildLimits extends Limits
{
    public const PAGE_SIZE = 25;
    public const TRIPLE = self::PAGE_SIZE * 3;
    public const PARENT_DOUBLE = parent::DOUBLE;
}
