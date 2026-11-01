<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Data;

/** Identifier quoting for the statements the generator builds. Values never go through here (they are bound). */
final class Sql
{
    public static function identifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private function __construct()
    {
    }
}
