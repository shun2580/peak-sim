<?php

declare(strict_types=1);

namespace PeakSim\Source;

use PhpParser\Node\Stmt\ClassLike;

final readonly class ClassInfo
{
    /**
     * @param string $kind class | interface | trait | enum
     * @param list<string> $interfaces
     * @param list<string> $traits
     */
    public function __construct(
        public string $fqcn,
        public string $file,
        public string $kind,
        public bool $isAbstract,
        public ?string $parent,
        public array $interfaces,
        public array $traits,
        public ClassLike $node,
    ) {
    }
}
