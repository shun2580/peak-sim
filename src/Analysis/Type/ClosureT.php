<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Type;

use PhpParser\Node;

/** A closure or arrow function, evaluated when it is called. $captured is the variables it closes over. */
final class ClosureT extends Type
{
    /**
     * @param Node\Expr\Closure|Node\Expr\ArrowFunction $node
     * @param array<string, Type> $captured
     * @param string $file app-relative file of the node; it makes the fingerprint unique across files
     */
    public function __construct(
        public readonly Node $node,
        public readonly array $captured = [],
        public readonly string $file = '',
    ) {
    }

    /** @param array<string, Type> $captured */
    public function withCaptured(array $captured): self
    {
        return new self($this->node, $captured, $this->file);
    }

    public function fingerprint(): string
    {
        $parts = [];
        foreach ($this->captured as $name => $type) {
            $parts[$name] = $name . '=' . $type->fingerprint();
        }
        ksort($parts, SORT_STRING);

        return 'closure:' . $this->file . ':' . $this->node->getStartLine() . ':' . $this->node->getStartFilePos()
            . '(' . implode(',', $parts) . ')';
    }
}
