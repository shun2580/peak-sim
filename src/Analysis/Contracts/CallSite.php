<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Contracts;

use PeakSim\Analysis\Type\Type;
use PhpParser\Node;

/** One call being walked, handed to the handlers. */
final class CallSite
{
    /** @var array<int, Type> */
    private array $evaluated = [];

    /**
     * @param list<Node\Arg> $args
     * @param \Closure(int): Type|null $argEvaluator evaluates argument $i in the caller's scope (the engine passes it)
     */
    public function __construct(
        public readonly Node $node,
        public readonly ?Type $receiver,
        public readonly ?string $staticClass,
        public readonly string $name,
        public readonly array $args,
        private readonly ?\Closure $argEvaluator = null,
    ) {
    }

    /**
     * The type of argument $i. Evaluated on the first ask only, so its events are emitted once.
     * Closures and literals are not evaluated here; they come back as ClosureT or a plain value.
     */
    public function argType(int $i): Type
    {
        if (!isset($this->args[$i])) {
            throw new \OutOfRangeException("call has no argument #{$i}");
        }
        if ($this->argEvaluator === null) {
            throw new \LogicException('this call site has no argument evaluator');
        }

        return $this->evaluated[$i] ??= ($this->argEvaluator)($i);
    }

    public function argNode(int $i): ?Node\Expr
    {
        return isset($this->args[$i]) ? $this->args[$i]->value : null;
    }
}
