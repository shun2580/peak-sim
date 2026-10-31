<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Engine;

use PeakSim\Analysis\Type\ClosureT;
use PeakSim\Analysis\Type\Type;
use PhpParser\Node;

/**
 * The walker side of the engine: it knows how to read statements and expressions. Call resolution, summaries,
 * the depth limit and recursion live on the other side (Engine and CallResolver) and reach the walker only
 * through this interface, so the two halves can be written by two people.
 */
interface BodyAnalyzer
{
    /**
     * Walk statements in $run (events, gaps and assumptions go to the run) and return the joined value of every
     * return statement, or null when the body returns nothing.
     *
     * @param list<Node\Stmt> $stmts
     */
    public function analyzeBody(array $stmts, Scope $scope, MethodRun $run, ?Node $at = null): ?Type;

    public function evaluate(Node\Expr $e, Scope $scope, MethodRun $run): Type;

    /** Evaluate a closure body once, in $run, with the arguments bound to its parameters. @param list<Type> $argTypes */
    public function invokeClosure(ClosureT $closure, array $argTypes, MethodRun $run): Type;
}
