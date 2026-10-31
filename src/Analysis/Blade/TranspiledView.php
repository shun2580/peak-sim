<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Blade;

use PeakSim\Model\Gap;

/**
 * The result of turning one Blade template into PHP source.
 *
 * - php: source to hand to a PHP parser. Line N of it comes from line N of the template.
 * - gaps: syntax the transpiler could not follow (BLADE_UNSUPPORTED), in template order.
 * - includes: every `@include` / `@extends`, in template order.
 */
final readonly class TranspiledView
{
    /**
     * @param list<Gap> $gaps
     * @param list<IncludeRef> $includes
     */
    public function __construct(
        public string $php,
        public array $gaps,
        public array $includes,
    ) {
    }
}
