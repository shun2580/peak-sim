<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Serialization;

use PeakSim\Source\ConstValue;
use PeakSim\Source\SourceIndex;

/** The default value of a property a class (or the nearest app ancestor) declares. */
final class ClassProperties
{
    private const MAX_DEPTH = 20;

    /** @return ConstValue|null null when no app class in the ancestry declares the property or it has no default */
    public static function default(SourceIndex $source, string $class, string $property): ?ConstValue
    {
        $current = ltrim($class, '\\');
        for ($depth = 0; $current !== '' && $depth < self::MAX_DEPTH; $depth++) {
            $info = $source->findClass($current);
            if ($info === null) {
                return null;
            }
            foreach ($info->node->getProperties() as $declaration) {
                foreach ($declaration->props as $item) {
                    if ($item->name->toString() === $property && $item->default !== null) {
                        return $source->evaluate($item->default, $current);
                    }
                }
            }
            $current = $info->parent === null ? '' : ltrim($info->parent, '\\');
        }

        return null;
    }
}
