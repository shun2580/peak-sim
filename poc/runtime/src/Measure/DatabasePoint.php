<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

/**
 * One measurement database to build: a data plan (the tag), the scale it is built at, and the recipe the builder
 * follows. The same tag and scale always mean the same recipe, so routes that share a plan share the database.
 */
final class DatabasePoint
{
    /** @param array<string, mixed> $recipe */
    public function __construct(
        public readonly string $tag,
        public readonly int $scale,
        public readonly array $recipe,
    ) {
        if (preg_match('/^[a-z][a-z0-9]{0,7}$/D', $tag) !== 1) {
            throw new \InvalidArgumentException('a tag is 1-8 characters of [a-z0-9], starting with a letter');
        }
        if ($scale < 0) {
            throw new \InvalidArgumentException('a scale is 0 or more');
        }
    }

    public function key(): string
    {
        return $this->tag . ':' . $this->scale;
    }
}
