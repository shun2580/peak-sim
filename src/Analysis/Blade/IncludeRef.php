<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Blade;

/**
 * A template the transpiled view pulls in: an `@include` or an `@extends`.
 * A null name means the name is not a plain string literal, so the transpiler cannot know
 * which template it is; the view handler decides what to do with it.
 */
final readonly class IncludeRef
{
    public const INCLUDE = 'include';
    public const EXTENDS = 'extends';

    /** @param self::INCLUDE|self::EXTENDS $kind */
    public function __construct(
        public string $kind,
        public ?string $name,
        public int $line,
    ) {
    }

    public function isDynamic(): bool
    {
        return $this->name === null;
    }
}
