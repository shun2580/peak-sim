<?php

declare(strict_types=1);

namespace PeakSim\Source;

use PhpParser\Node\Stmt\ClassMethod;

/** A method found for a class. $owner declares it; $via is the trait it came through, if any. */
final readonly class MethodRef
{
    public function __construct(
        public string $owner,
        public string $file,
        public ClassMethod $node,
        public ?string $via = null,
    ) {
    }
}
