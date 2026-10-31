<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Engine;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/** Tells a generator (a function whose own body holds a yield) from a function that only has one in a closure. */
final class GeneratorDetector
{
    public static function isGenerator(Node\FunctionLike $function): bool
    {
        $visitor = new class () extends NodeVisitorAbstract {
            public bool $found = false;

            public function enterNode(Node $node): int|Node|null
            {
                if ($node instanceof Expr\Yield_ || $node instanceof Expr\YieldFrom) {
                    $this->found = true;

                    return NodeVisitor::STOP_TRAVERSAL;
                }
                // A closure, an arrow function or a class declared in the body has its own yields.
                if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction || $node instanceof Node\Stmt\ClassLike || $node instanceof Node\Stmt\Function_) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                return null;
            }
        };
        (new NodeTraverser($visitor))->traverse($function->getStmts() ?? []);

        return $visitor->found;
    }
}
