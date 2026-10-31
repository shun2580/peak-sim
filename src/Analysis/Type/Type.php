<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Type;

/**
 * A simply inferred value type. All types are immutable; changing one returns a new value.
 * fingerprint() is stable and is used in summary keys, so it must cover everything that changes the result.
 */
abstract class Type
{
    abstract public function fingerprint(): string;

    /**
     * @param list<string> $names
     * @return list<string> sorted and without duplicates
     */
    protected static function normalizeNames(array $names): array
    {
        $names = array_values(array_unique($names));
        sort($names, SORT_STRING);

        return $names;
    }
}
