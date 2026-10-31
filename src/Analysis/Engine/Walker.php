<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Engine;

use PeakSim\Analysis\Type\ClosureT;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\ObjectT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\ShapeT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Analysis\Type\UnionT;
use PeakSim\Analysis\Type\UnknownT;
use PeakSim\Model\AssumptionCode;
use PeakSim\Model\Axis;
use PeakSim\Model\Cardinality;
use PeakSim\Model\CardinalityKind;
use PeakSim\Model\CostEvent;
use PeakSim\Model\Delivery;
use PeakSim\Model\EventKind;
use PeakSim\Model\Frame;
use PeakSim\Model\GapCode;
use PeakSim\Model\MemoryCause;
use PeakSim\Model\MemoryPart;
use PeakSim\Model\Origin;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;

/**
 * Reads statements and expressions top to bottom and records what costs something. It keeps the type of each
 * variable (Scope), follows both sides of a branch, and turns a loop over database rows into a Frame.
 *
 * Rules that are easy to get wrong:
 *  - a loop over a bounded list makes no frame; over a table-sized list it makes one frame (streamed for chunk / lazy);
 *    the window a chunk callback receives is not looped again (N/c x c = N);
 *  - a foreach over something whose size is not known, a for / while / do-while, and a loop whose body does anything
 *    (an event or a gap) is a gap, never a guess;
 *  - an UnknownT is made only through EvalContext::gap() (invariant I1).
 */
final class Walker implements BodyAnalyzer
{
    private const SUPERGLOBALS = ['_GET', '_POST', '_SERVER', '_REQUEST', '_COOKIE', '_FILES', '_ENV', '_SESSION', 'GLOBALS', 'argv', 'argc'];

    private const MAX_CLOSURE_NESTING = 64;

    private int $closureNesting = 0;

    /** @var \WeakMap<ClosureT, array{scope: Scope, refs: list<string>}> where a closure was made, for the variables it captured by reference */
    private \WeakMap $origins;

    /** @var \WeakMap<Node, true> the `$x->merge(..)` calls whose result is assigned back to `$x` */
    private \WeakMap $selfMerges;

    public function __construct(
        private readonly Engine $engine,
    ) {
        $this->origins = new \WeakMap();
        $this->selfMerges = new \WeakMap();
    }

    // ----- BodyAnalyzer ------------------------------------------------------------------------------------

    public function analyzeBody(array $stmts, Scope $scope, MethodRun $run, ?Node $at = null): ?Type
    {
        $this->walkStmts($stmts, $scope, $run);
        $returns = $scope->returns->types;
        if ($returns === []) {
            return null;
        }

        return $this->joinTypes($returns, $at ?? $stmts[0] ?? new Stmt\Nop(), $scope, $run);
    }

    public function evaluate(Expr $e, Scope $scope, MethodRun $run): Type
    {
        $type = $this->doEvaluate($e, $scope, $run);
        if ($this->engine->options()->auditUnknown) {
            $this->engine->audit($type, $scope->file . ':' . $e->getStartLine());
        }

        return $type;
    }

    public function invokeClosure(ClosureT $closure, array $argTypes, MethodRun $run): Type
    {
        $node = $closure->node;
        $captured = $closure->captured;
        $selfType = $captured['this'] ?? null;
        $classType = $captured['#class'] ?? $selfType;
        $class = $classType instanceof ObjectT || $classType instanceof ModelT ? $classType->class : null;
        $scope = new Scope($closure->file !== '' ? $closure->file : $run->file, $class, $class, $selfType);
        $frames = count($run->frames);
        $origin = $this->origins[$closure] ?? null;
        foreach ($captured as $name => $type) {
            if ($name !== 'this' && $name !== '#class') {
                $scope->set($name, $type, $frames);
                if ($origin !== null && in_array($name, $origin['refs'], true)) {
                    // Captured by reference: it is the outer variable, declared where the outer scope declared it.
                    $scope->declaredAt[$name] = $origin['scope']->declaredAt[$name] ?? 0;
                }
            }
        }
        if ($this->closureNesting >= self::MAX_CLOSURE_NESTING) {
            return $this->gap($run, $scope, GapCode::RECURSION, $node, 'closures call each other too deeply: not followed');
        }

        $this->closureNesting++;
        try {
            $this->engine->resolver()->bindParameters($node->params, $argTypes, [], $scope, $run, true);
            if ($node instanceof Expr\ArrowFunction) {
                return $this->evaluate($node->expr, $scope, $run);
            }
            $returned = $this->analyzeBody($node->stmts, $scope, $run, $node) ?? new PlainT();
            if ($origin !== null) {
                foreach ($origin['refs'] as $name) {
                    if (isset($scope->vars[$name])) {
                        $before = $origin['scope']->vars[$name] ?? null;
                        $origin['scope']->vars[$name] = $before === null ? $scope->vars[$name] : TypeMerge::joinAll([$before, $scope->vars[$name]])->type;
                    }
                }
            }

            return $returned;
        } finally {
            $this->closureNesting--;
        }
    }

    /** An argument: a closure is captured, not run; anything else is evaluated. */
    public function evaluateArg(Expr $e, Scope $scope, MethodRun $run): Type
    {
        return $e instanceof Expr\Closure || $e instanceof Expr\ArrowFunction
            ? $this->makeClosure($e, $scope, $run)
            : $this->evaluate($e, $scope, $run);
    }

    // ----- statements --------------------------------------------------------------------------------------

    /** @param array<Node\Stmt> $stmts */
    private function walkStmts(array $stmts, Scope $scope, MethodRun $run): void
    {
        foreach ($stmts as $stmt) {
            $this->walkStmt($stmt, $scope, $run);
        }
    }

    private function walkStmt(Stmt $s, Scope $scope, MethodRun $run): void
    {
        switch (true) {
            case $s instanceof Stmt\Expression:
                $this->evaluate($s->expr, $scope, $run);
                break;
            case $s instanceof Stmt\Return_:
                $scope->returns->types[] = $s->expr === null ? new PlainT() : $this->evaluateArg($s->expr, $scope, $run);
                break;
            case $s instanceof Stmt\Echo_:
                foreach ($s->exprs as $expr) {
                    $this->evaluate($expr, $scope, $run);
                }
                break;
            case $s instanceof Stmt\If_:
                $this->ifStmt($s, $scope, $run);
                break;
            case $s instanceof Stmt\Foreach_:
                $this->foreachStmt($s, $scope, $run);
                break;
            case $s instanceof Stmt\For_:
            case $s instanceof Stmt\While_:
            case $s instanceof Stmt\Do_:
                $this->unboundedLoop($s, $scope, $run);
                break;
            case $s instanceof Stmt\Switch_:
                $this->switchStmt($s, $scope, $run);
                break;
            case $s instanceof Stmt\TryCatch:
                $this->tryStmt($s, $scope, $run);
                break;
            case $s instanceof Stmt\Block:
            case $s instanceof Stmt\Namespace_:
            case $s instanceof Stmt\Declare_:
                $this->walkStmts($s->stmts ?? [], $scope, $run);
                break;
            case $s instanceof Stmt\Global_:
                foreach ($s->vars as $var) {
                    if ($var instanceof Expr\Variable && is_string($var->name)) {
                        $scope->set($var->name, new PlainT(), count($run->frames));
                    }
                }
                break;
            case $s instanceof Stmt\Static_:
                foreach ($s->vars as $var) {
                    if (is_string($var->var->name)) {
                        $scope->set($var->var->name, $var->default !== null ? $this->evaluate($var->default, $scope, $run) : new PlainT(), count($run->frames));
                    }
                }
                break;
            case $s instanceof Stmt\Unset_:
            case $s instanceof Stmt\Nop:
            case $s instanceof Stmt\Break_:
            case $s instanceof Stmt\Continue_:
            case $s instanceof Stmt\InlineHTML:
            case $s instanceof Stmt\Use_:
            case $s instanceof Stmt\GroupUse:
            case $s instanceof Stmt\Const_:
            case $s instanceof Stmt\Function_:
            case $s instanceof Stmt\ClassLike:
            case $s instanceof Stmt\HaltCompiler:
            case $s instanceof Stmt\Label:
                break;
            default:
                $this->gap($run, $scope, GapCode::UNRESOLVED_CALL, $s, 'statement ' . (new \ReflectionClass($s))->getShortName() . ' is not followed');
        }
    }

    private function ifStmt(Stmt\If_ $s, Scope $scope, MethodRun $run): void
    {
        $this->evaluate($s->cond, $scope, $run);
        $branches = [];
        $then = $scope->fork();
        $this->walkStmts($s->stmts, $then, $run);
        $branches[] = [$then, $this->exits($s->stmts)];
        foreach ($s->elseifs as $elseif) {
            $this->evaluate($elseif->cond, $scope, $run);
            $branch = $scope->fork();
            $this->walkStmts($elseif->stmts, $branch, $run);
            $branches[] = [$branch, $this->exits($elseif->stmts)];
        }
        if ($s->else !== null) {
            $else = $scope->fork();
            $this->walkStmts($s->else->stmts, $else, $run);
            $branches[] = [$else, $this->exits($s->else->stmts)];
        } else {
            $branches[] = [$scope->fork(), false];
        }
        $this->mergeBranches($scope, $branches, $s, $run);
    }

    private function switchStmt(Stmt\Switch_ $s, Scope $scope, MethodRun $run): void
    {
        $this->evaluate($s->cond, $scope, $run);
        $branches = [];
        foreach ($s->cases as $case) {
            if ($case->cond !== null) {
                $this->evaluate($case->cond, $scope, $run);
            }
            $branch = $scope->fork();
            $this->walkStmts($case->stmts, $branch, $run);
            $branches[] = [$branch, $this->exits($case->stmts)];
        }
        $branches[] = [$scope->fork(), false];
        $this->mergeBranches($scope, $branches, $s, $run);
    }

    private function tryStmt(Stmt\TryCatch $s, Scope $scope, MethodRun $run): void
    {
        $branches = [];
        $try = $scope->fork();
        $this->walkStmts($s->stmts, $try, $run);
        $branches[] = [$try, $this->exits($s->stmts)];
        foreach ($s->catches as $catch) {
            $branch = $scope->fork();
            if ($catch->var !== null && is_string($catch->var->name)) {
                $branch->set($catch->var->name, new PlainT(), count($run->frames));
            }
            $this->walkStmts($catch->stmts, $branch, $run);
            $branches[] = [$branch, $this->exits($catch->stmts)];
        }
        $this->mergeBranches($scope, $branches, $s, $run);
        if ($s->finally !== null) {
            $this->walkStmts($s->finally->stmts, $scope, $run);
        }
    }

    private function foreachStmt(Stmt\Foreach_ $s, Scope $scope, MethodRun $run): void
    {
        $source = $this->evaluate($s->expr, $scope, $run);
        $before = $run->activity();
        $frame = null;
        $sizeUnknown = false;
        $elem = new PlainT();

        if ($source instanceof ListT) {
            $elem = $source->elem;
            if ($source->card->kind === CardinalityKind::Table && !$source->window) {
                $origin = $source->card->origin ?? new Origin(null);
                $frame = new Frame($origin, $source->delivery === Delivery::Streamed, $this->ctx($scope, $run)->site($s));
                if ($elem instanceof ModelT && $elem->origin === null) {
                    $elem = $elem->withOrigin($origin);
                }
            } elseif ($source->card->kind === CardinalityKind::Unknown) {
                $sizeUnknown = true;
            }
        } elseif ($source instanceof ShapeT) {
            $elem = TypeMerge::joinAll(array_values($source->entries))->type;
        } elseif ($source instanceof UnknownT) {
            $sizeUnknown = true;
            $elem = $source;
        } else {
            $sizeUnknown = true;
        }

        $body = $scope->fork();
        $this->assignTo($s->valueVar, $elem, $body, $run);
        if ($s->keyVar !== null) {
            $this->assignTo($s->keyVar, new PlainT(), $body, $run);
        }
        $walk = function () use ($s, $body, $run): void {
            $this->walkStmts($s->stmts, $body, $run);
        };
        if ($frame !== null) {
            $this->ctx($scope, $run)->withFrame($frame, $walk);
        } else {
            $walk();
        }

        if ($sizeUnknown && $run->activity() > $before) {
            $this->gap($run, $scope, GapCode::LOOP_SOURCE_UNKNOWN, $s, 'foreach over a value whose size is not known, and its body does something');
        }
        // The body may run zero times or more: the state after is both.
        $this->mergeBranches($scope, [[$scope->fork(), false], [$body, false]], $s, $run, true);
    }

    /** for / while / do-while: no bound is derived, so a body that does anything is a gap. */
    private function unboundedLoop(Stmt\For_|Stmt\While_|Stmt\Do_ $s, Scope $scope, MethodRun $run): void
    {
        $before = $run->activity();
        $body = $scope->fork();
        if ($s instanceof Stmt\For_) {
            foreach ([...$s->init, ...$s->cond, ...$s->loop] as $expr) {
                $this->evaluate($expr, $body, $run);
            }
        } else {
            $this->evaluate($s->cond, $body, $run);
        }
        $this->walkStmts($s->stmts, $body, $run);

        if ($run->activity() > $before) {
            $kind = match (true) {
                $s instanceof Stmt\For_ => 'for',
                $s instanceof Stmt\While_ => 'while',
                default => 'do-while',
            };
            $this->gap($run, $scope, GapCode::LOOP_BOUND_UNKNOWN, $s, "{$kind} loop whose body does something: how often it runs is not derived");
        }
        $this->mergeBranches($scope, [[$scope->fork(), false], [$body, false]], $s, $run, true);
    }

    /** @param array<Node\Stmt> $stmts */
    private function exits(array $stmts): bool
    {
        $last = end($stmts);

        return $last instanceof Stmt\Return_
            || ($last instanceof Stmt\Expression && $last->expr instanceof Expr\Throw_);
    }

    /**
     * After a branch, a variable holds the join of what the branches left in it. A branch that ends in a return
     * or throw does not reach the code after it, unless every branch does.
     *
     * @param list<array{0: Scope, 1: bool}> $branches
     */
    private function mergeBranches(Scope $into, array $branches, Node $at, MethodRun $run, bool $afterLoop = false): void
    {
        $live = array_values(array_filter($branches, static fn (array $b): bool => !$b[1]));
        $scopes = array_map(static fn (array $b): Scope => $b[0], $live === [] ? $branches : $live);

        $names = [];
        foreach ($scopes as $s) {
            foreach (array_keys($s->vars) as $name) {
                $names[$name] = true;
            }
        }
        foreach (array_keys($names) as $name) {
            $types = [];
            foreach ($scopes as $s) {
                if (isset($s->vars[$name])) {
                    $types[] = $s->vars[$name];
                }
            }
            $joined = count($types) === 1 ? $types[0] : $this->joinTypes($types, $at, $into, $run, !$afterLoop);
            $into->vars[$name] = $joined;
            $into->declaredAt[$name] ??= count($run->frames);
        }
    }

    // ----- expressions -------------------------------------------------------------------------------------

    private function doEvaluate(Expr $e, Scope $scope, MethodRun $run): Type
    {
        $resolver = $this->engine->resolver();

        return match (true) {
            $e instanceof Expr\Variable => $this->variable($e, $scope, $run),
            $e instanceof Expr\Assign, $e instanceof Expr\AssignRef => $this->assign($e, $scope, $run),
            $e instanceof Expr\AssignOp\Coalesce => $this->coalesceAssign($e, $scope, $run),
            $e instanceof Expr\AssignOp => $this->assignOp($e, $scope, $run),
            $e instanceof Expr\MethodCall, $e instanceof Expr\NullsafeMethodCall => $resolver->methodCall($e, $scope, $run),
            $e instanceof Expr\StaticCall => $resolver->staticCall($e, $scope, $run),
            $e instanceof Expr\FuncCall => $resolver->funcCall($e, $scope, $run),
            $e instanceof Expr\New_ => $resolver->newExpr($e, $scope, $run),
            $e instanceof Expr\PropertyFetch, $e instanceof Expr\NullsafePropertyFetch => $resolver->propertyFetch($e, $scope, $run),
            $e instanceof Expr\ArrayDimFetch => $this->dimFetch($e, $scope, $run),
            $e instanceof Expr\Array_ => $this->arrayLiteral($e, $scope, $run),
            $e instanceof Expr\Closure, $e instanceof Expr\ArrowFunction => $this->makeClosure($e, $scope, $run),
            $e instanceof Expr\Ternary => $this->ternary($e, $scope, $run),
            $e instanceof Expr\Match_ => $this->matchExpr($e, $scope, $run),
            $e instanceof Expr\BinaryOp\Coalesce => $this->joinTypes([$this->evaluate($e->left, $scope, $run), $this->evaluate($e->right, $scope, $run)], $e, $scope, $run),
            $e instanceof Expr\BinaryOp => $this->plainOf([$e->left, $e->right], $scope, $run),
            $e instanceof Expr\Cast\Array_, $e instanceof Expr\Cast\Object_ => $this->evaluate($e->expr, $scope, $run),
            $e instanceof Expr\Cast, $e instanceof Expr\UnaryMinus, $e instanceof Expr\UnaryPlus, $e instanceof Expr\BooleanNot,
            $e instanceof Expr\BitwiseNot, $e instanceof Expr\Print_, $e instanceof Expr\ErrorSuppress, $e instanceof Expr\Instanceof_,
            $e instanceof Expr\PreInc, $e instanceof Expr\PreDec, $e instanceof Expr\PostInc, $e instanceof Expr\PostDec,
            $e instanceof Expr\Empty_ => $this->plainOf([$this->operandOf($e)], $scope, $run),
            $e instanceof Expr\Isset_ => $this->plainOf($e->vars, $scope, $run),
            $e instanceof Expr\Exit_ => $this->plainOf($e->expr === null ? [] : [$e->expr], $scope, $run),
            $e instanceof Expr\Clone_ => $this->evaluate($e->expr, $scope, $run),
            $e instanceof Expr\Throw_ => $this->throwExpr($e, $scope, $run),
            $e instanceof Scalar\InterpolatedString => $this->plainOf(array_values(array_filter($e->parts, static fn (Node $p): bool => $p instanceof Expr)), $scope, $run),
            $e instanceof Expr\ClassConstFetch => $this->constant($e, $scope),
            $e instanceof Scalar, $e instanceof Expr\ConstFetch => new PlainT(),
            $e instanceof Expr\Yield_, $e instanceof Expr\YieldFrom => $this->gap($run, $scope, GapCode::GENERATOR_NOT_ANALYZED, $e, 'generators are not followed'),
            $e instanceof Expr\StaticPropertyFetch => $this->gap($run, $scope, GapCode::UNRESOLVED_CALL, $e, 'static property reads are not followed'),
            $e instanceof Expr\Include_, $e instanceof Expr\Eval_, $e instanceof Expr\ShellExec => $this->gap($run, $scope, GapCode::UNRESOLVED_CALL, $e, 'include / eval / shell execution is not followed'),
            default => $this->gap($run, $scope, GapCode::UNRESOLVED_CALL, $e, 'expression ' . (new \ReflectionClass($e))->getShortName() . ' is not followed'),
        };
    }

    private function operandOf(Expr $e): Expr
    {
        return match (true) {
            $e instanceof Expr\Instanceof_ => $e->expr,
            $e instanceof Expr\PreInc, $e instanceof Expr\PreDec, $e instanceof Expr\PostInc, $e instanceof Expr\PostDec => $e->var,
            default => $e->expr,
        };
    }

    /** @param array<Expr> $exprs */
    private function plainOf(array $exprs, Scope $scope, MethodRun $run): Type
    {
        foreach ($exprs as $expr) {
            $this->evaluate($expr, $scope, $run);
        }

        return new PlainT();
    }

    private function variable(Expr\Variable $e, Scope $scope, MethodRun $run): Type
    {
        if (!is_string($e->name)) {
            $this->evaluate($e->name, $scope, $run);

            return $this->gap($run, $scope, GapCode::UNRESOLVED_CALL, $e, 'variable variable is not followed');
        }
        if ($e->name === 'this') {
            return $scope->selfType ?? $this->gap($run, $scope, GapCode::UNRESOLVED_CALL, $e, '$this has no known type here');
        }
        $type = $scope->get($e->name);
        if ($type !== null) {
            return $type;
        }
        if (in_array($e->name, self::SUPERGLOBALS, true)) {
            return new PlainT();
        }

        return $this->gap($run, $scope, GapCode::UNRESOLVED_CALL, $e, "variable \${$e->name} has no known type here");
    }

    private function assign(Expr\Assign|Expr\AssignRef $e, Scope $scope, MethodRun $run): Type
    {
        if ($e->var instanceof Expr\Variable && $e->expr instanceof Expr\MethodCall && $e->expr->var instanceof Expr\Variable
            && is_string($e->var->name) && $e->var->name === $e->expr->var->name
            && $e->expr->name instanceof Node\Identifier && in_array(strtolower($e->expr->name->toString()), ['merge', 'concat'], true)) {
            $this->selfMerges[$e->expr] = true;
        }
        $value = $this->evaluateArg($e->expr, $scope, $run);
        $this->assignTo($e->var, $value, $scope, $run);

        return $value;
    }

    private function coalesceAssign(Expr\AssignOp\Coalesce $e, Scope $scope, MethodRun $run): Type
    {
        $value = $this->evaluateArg($e->expr, $scope, $run);
        if ($e->var instanceof Expr\Variable && is_string($e->var->name)) {
            $current = $scope->get($e->var->name);
            $value = $current === null ? $value : $this->joinTypes([$current, $value], $e, $scope, $run);
        }
        $this->assignTo($e->var, $value, $scope, $run);

        return $value;
    }

    private function assignOp(Expr\AssignOp $e, Scope $scope, MethodRun $run): Type
    {
        $this->evaluate($e->expr, $scope, $run);
        $plain = new PlainT();
        if ($e->var instanceof Expr\Variable && is_string($e->var->name) && $scope->get($e->var->name) === null) {
            $scope->set($e->var->name, $plain, count($run->frames));
        }

        return $plain;
    }

    private function assignTo(Expr $target, Type $value, Scope $scope, MethodRun $run): void
    {
        if ($target instanceof Expr\Variable && is_string($target->name)) {
            $scope->set($target->name, $value, count($run->frames));
        } elseif ($target instanceof Expr\ArrayDimFetch) {
            if ($target->dim !== null) {
                $this->evaluate($target->dim, $scope, $run);
            }
            $this->appendTo($target, $value, $scope, $run);
        } elseif ($target instanceof Expr\List_ || $target instanceof Expr\Array_) {
            $this->destructure($target, $value, $scope, $run);
        }
        // A property or a static property: the value is not tracked (reads use the declared type).
    }

    private function destructure(Expr\List_|Expr\Array_ $target, Type $value, Scope $scope, MethodRun $run): void
    {
        foreach ($target->items as $index => $item) {
            if ($item === null) {
                continue;
            }
            $part = match (true) {
                $value instanceof ListT => $value->elem,
                $value instanceof ShapeT => $this->shapeEntry($value, $item->key) ?? TypeMerge::joinAll(array_values($value->entries))->type,
                $value instanceof UnknownT => $value,
                default => new PlainT(),
            };
            $this->assignTo($item->value, $part, $scope, $run);
        }
    }

    private function shapeEntry(ShapeT $shape, ?Expr $key): ?Type
    {
        return $key instanceof Scalar\String_ ? ($shape->entries[$key->value] ?? null) : null;
    }

    /**
     * `$rows[] = $v` inside a loop below the place $rows was made: $rows now grows with that loop. When the loop is
     * streamed, holding the rows is exactly what chunk / lazy / cursor were meant to avoid, so it is memory (a heuristic).
     */
    private function appendTo(Expr\ArrayDimFetch $target, Type $value, Scope $scope, MethodRun $run): void
    {
        $root = $target;
        while ($root instanceof Expr\ArrayDimFetch) {
            $root = $root->var;
        }
        if (!$root instanceof Expr\Variable || !is_string($root->name) || $target->var !== $root) {
            return;
        }
        $name = $root->name;
        $current = $scope->get($name);

        if ($current instanceof ShapeT && $target->dim instanceof Scalar\String_) {
            $scope->vars[$name] = $current->with($target->dim->value, $value);

            return;
        }
        $this->collectInto($name, $current, $value, $value, $target, $scope, $run);
    }

    /**
     * The same accumulation for a Collection that collects through a method: push / add / prepend / put on the
     * variable, or `$x = $x->merge(..)` / concat (isSelfMerge). $element is what the list now holds more of,
     * $held what is added (a list of table rows makes one more degree). The variable's new type is returned.
     */
    public function collectOnVariable(Expr\Variable $var, Type $element, Type $held, Node $at, Scope $scope, MethodRun $run): ?ListT
    {
        if (!is_string($var->name) || !($scope->get($var->name) instanceof ListT)) {
            return null;
        }

        return $this->collectInto($var->name, $scope->get($var->name), $element, $held, $at, $scope, $run);
    }

    /** `$x = $x->merge(..)` and concat: the call replaces the variable, which is an append to it. */
    public function isSelfMerge(Node $call): bool
    {
        return isset($this->selfMerges[$call]);
    }

    private function collectInto(string $name, ?Type $current, Type $element, Type $held, Node $at, Scope $scope, MethodRun $run): ?ListT
    {
        $now = count($run->frames);
        $declared = $scope->declaredAt[$name] ?? $now;

        if ($current === null || $current instanceof PlainT) {
            $list = new ListT($element, Cardinality::bounded());
        } elseif ($current instanceof ListT) {
            $list = $current->withElem(TypeMerge::joinAll([$current->elem, $element])->type);
        } elseif ($current instanceof ShapeT) {
            $list = new ListT(TypeMerge::joinAll([...array_values($current->entries), $element])->type, Cardinality::bounded());
        } else {
            return null;
        }

        $inner = array_slice($run->frames, $declared);
        if ($inner !== [] && $list->card->kind === CardinalityKind::Bounded) {
            $list = $list->withCard(Cardinality::table($inner[0]->origin->table, $inner[0]->origin->perTable));
        }
        $scope->vars[$name] = $list;

        $streamed = array_filter($inner, static fn (Frame $f): bool => $f->streamed);
        if ($streamed !== []) {
            $ctx = $this->ctx($scope, $run);
            $holdsRows = $held instanceof ListT && $held->card->kind === CardinalityKind::Table;
            $ctx->emit(new CostEvent(
                EventKind::Accumulate,
                $ctx->site($at),
                [],
                null,
                new MemoryPart($holdsRows ? 1 : 0, $holdsRows && $held instanceof ListT ? $held->card->origin : null, MemoryCause::Accumulate, $declared),
                $inner[0]->origin->table,
                [],
                $ctx->seq(),
            ));
            $ctx->assume(AssumptionCode::ACCUMULATION_HEURISTIC, $at, 'a value collected inside a streamed loop is counted as held in memory', [Axis::Memory]);
        }

        return $list;
    }

    private function dimFetch(Expr\ArrayDimFetch $e, Scope $scope, MethodRun $run): Type
    {
        $base = $this->evaluate($e->var, $scope, $run);
        if ($e->dim !== null) {
            $this->evaluate($e->dim, $scope, $run);
        }

        return match (true) {
            $base instanceof ListT => $base->elem,
            $base instanceof ShapeT => $this->shapeEntry($base, $e->dim) ?? TypeMerge::joinAll(array_values($base->entries))->type,
            $base instanceof PlainT, $base instanceof ModelT => new PlainT(),
            $base instanceof UnknownT => $base,
            default => $this->gap($run, $scope, GapCode::UNRESOLVED_CALL, $e, 'indexing a value of this kind is not followed'),
        };
    }

    private function arrayLiteral(Expr\Array_ $e, Scope $scope, MethodRun $run): Type
    {
        $values = [];
        $entries = [];
        $shape = $e->items !== [];
        $card = Cardinality::bounded();
        foreach ($e->items as $item) {
            if ($item === null) {
                continue;
            }
            if ($item->key !== null) {
                $this->evaluate($item->key, $scope, $run);
            }
            $type = $this->evaluateArg($item->value, $scope, $run);
            if ($item->unpack) {
                $shape = false;
                if ($type instanceof ListT) {
                    $values[] = $type->elem;
                    if ($type->card->kind !== CardinalityKind::Bounded) {
                        $card = $type->card;
                    }
                } else {
                    $values[] = $type;
                }
                continue;
            }
            $values[] = $type;
            if ($item->key instanceof Scalar\String_) {
                $entries[$item->key->value] = $type;
            } else {
                $shape = false;
            }
        }
        if ($shape) {
            return new ShapeT($entries);
        }

        return new ListT($values === [] ? new PlainT() : TypeMerge::joinAll($values)->type, $card);
    }

    private function makeClosure(Expr\Closure|Expr\ArrowFunction $e, Scope $scope, MethodRun $run): ClosureT
    {
        $captured = [];
        $refs = [];
        if ($e instanceof Expr\ArrowFunction) {
            $captured = $scope->vars;
        } else {
            foreach ($e->uses as $use) {
                if (is_string($use->var->name) && ($type = $scope->get($use->var->name)) !== null) {
                    $captured[$use->var->name] = $type;
                    if ($use->byRef) {
                        $refs[] = $use->var->name;
                    }
                }
            }
        }
        if (!($e instanceof Expr\Closure && $e->static) && $scope->selfType !== null) {
            $captured['this'] = $scope->selfType;
        }
        if ($scope->class !== null) {
            $captured['#class'] = new ObjectT($scope->class);
        }

        $closure = new ClosureT($e, $captured, $scope->file);
        if ($refs !== []) {
            $this->origins[$closure] = ['scope' => $scope, 'refs' => $refs];
        }

        return $closure;
    }

    private function ternary(Expr\Ternary $e, Scope $scope, MethodRun $run): Type
    {
        $cond = $this->evaluate($e->cond, $scope, $run);
        $then = $e->if === null ? $cond : $this->evaluateArg($e->if, $scope, $run);
        $else = $this->evaluateArg($e->else, $scope, $run);

        return $this->joinTypes([$then, $else], $e, $scope, $run);
    }

    private function matchExpr(Expr\Match_ $e, Scope $scope, MethodRun $run): Type
    {
        $this->evaluate($e->cond, $scope, $run);
        $results = [];
        foreach ($e->arms as $arm) {
            foreach ($arm->conds ?? [] as $cond) {
                $this->evaluate($cond, $scope, $run);
            }
            $results[] = $this->evaluateArg($arm->body, $scope, $run);
        }

        return $this->joinTypes($results === [] ? [new PlainT()] : $results, $e, $scope, $run);
    }

    private function throwExpr(Expr\Throw_ $e, Scope $scope, MethodRun $run): Type
    {
        // `throw new SomeException(...)`: the exception object is not used afterwards, so it is not resolved.
        if ($e->expr instanceof Expr\New_) {
            foreach ($e->expr->args as $arg) {
                if ($arg instanceof Node\Arg) {
                    $this->evaluateArg($arg->value, $scope, $run);
                }
            }
        } else {
            $this->evaluate($e->expr, $scope, $run);
        }

        return new PlainT();
    }

    private function constant(Expr\ClassConstFetch $e, Scope $scope): Type
    {
        $value = $this->engine->source()->evaluate($e, $scope->class);
        if ($value->known && is_array($value->value)) {
            return new ListT(new PlainT(), Cardinality::bounded());
        }

        return new PlainT();
    }

    // ----- helpers -----------------------------------------------------------------------------------------

    /**
     * @param list<Type> $types
     * @param bool $reportLoss false after a loop: the state before and after it differ by design, which is not a guess
     */
    private function joinTypes(array $types, Node $at, Scope $scope, MethodRun $run, bool $reportLoss = true): Type
    {
        $joined = TypeMerge::joinAll($types);
        if ($joined->type instanceof UnionT && $joined->type->isTooLarge()) {
            return $this->gap($run, $scope, GapCode::UNION_TOO_LARGE, $at, 'a value can have more than ' . UnionT::MAX_MEMBERS . ' different types here');
        }
        if ($joined->lossy && $reportLoss) {
            $this->ctx($scope, $run)->assume(AssumptionCode::BRANCH_WORST_CASE, $at, 'branches give different precision; the worse side is adopted');
        }

        return $joined->type;
    }

    private function ctx(Scope $scope, MethodRun $run): EvalContextImpl
    {
        return new EvalContextImpl($this->engine, $run, $scope);
    }

    private function gap(MethodRun $run, Scope $scope, GapCode $code, Node $at, string $message): UnknownT
    {
        return $this->ctx($scope, $run)->gap($code, $at, $message);
    }
}
