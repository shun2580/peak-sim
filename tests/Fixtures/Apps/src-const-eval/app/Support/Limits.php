<?php

namespace App\Support;

class Limits
{
    public const PAGE_SIZE = 50;
    public const DOUBLE = self::PAGE_SIZE * 2;
    public const CHUNK = 1000 / 10;
    public const HALF = 7 / 2;
    public const REMAINDER = 10 % 3;
    public const NEGATIVE = -self::PAGE_SIZE;
    public const LABEL = 'page-' . self::PAGE_SIZE;
    public const SIZES = [10, 20, self::PAGE_SIZE];
    public const ENABLED = true;
    public const LOOPS_A = self::LOOPS_B;
    public const LOOPS_B = self::LOOPS_A;
    public const FROM_GLOBAL = PHP_INT_SIZE;
}
