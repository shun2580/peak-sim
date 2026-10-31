<?php

declare(strict_types=1);

namespace PeakSim\Binding;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/** The returns and variable assignments of one function body; nested functions and classes are skipped. */
final class FunctionBody
{
    /** @var list<Stmt\Return_> */
    public readonly array $returns;

    /** @var array<string, list<Expr>> variable name => every value assigned to it */
    public readonly array $assignments;

    /** @param array<Stmt> $stmts */
    public function __construct(array $stmts)
    {
        $visitor = new class () extends NodeVisitorAbstract {
            /** @var list<Stmt\Return_> */
            public array $returns = [];

            /** @var array<string, list<Expr>> */
            public array $assignments = [];

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction || $node instanceof Stmt\ClassLike || $node instanceof Stmt\Function_) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }
                if ($node instanceof Stmt\Return_) {
                    $this->returns[] = $node;
                } elseif ($node instanceof Expr\Assign && $node->var instanceof Expr\Variable && is_string($node->var->name)) {
                    $this->assignments[$node->var->name][] = $node->expr;
                }

                return null;
            }
        };
        (new NodeTraverser($visitor))->traverse($stmts);
        $this->returns = $visitor->returns;
        $this->assignments = $visitor->assignments;
    }
}
