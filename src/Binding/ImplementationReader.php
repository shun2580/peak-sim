<?php

declare(strict_types=1);

namespace PeakSim\Binding;

use PeakSim\Model\Site;
use PeakSim\Source\ParseFailed;
use PeakSim\Source\SourceIndex;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;

/**
 * Reads the second argument of a binding. A class constant or a string is the class. A closure, a
 * first-class callable or an array callable is read for the classes it can return. Anything that is
 * not followed makes the whole read fail: a guess here would hide an implementation.
 */
final class ImplementationReader
{
    private const MAX_DEPTH = 8;

    public function __construct(private readonly SourceIndex $source)
    {
    }

    public function read(Expr $impl, string $context, string $file): ImplementationRead
    {
        try {
            $classes = $this->follow($impl, $context, $file, 0);
        } catch (Unreadable $e) {
            return ImplementationRead::failed($e->getMessage());
        } catch (ParseFailed $e) {
            return ImplementationRead::failed($e->getMessage());
        }

        return $classes === []
            ? ImplementationRead::failed('the implementation names no class')
            : ImplementationRead::found($classes);
    }

    /** The class a constant expression names, or null. */
    public function className(Expr $e, string $context): ?string
    {
        $value = $this->source->evaluate($e, $context);

        return $value->known && is_string($value->value) && $value->value !== '' ? ltrim($value->value, '\\') : null;
    }

    /** @return array<string, Site> */
    private function follow(Expr $impl, string $context, string $file, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new Unreadable('the callable chain is too deep');
        }
        if ($impl instanceof Expr\Closure) {
            return $this->returned($impl->stmts, $context, $file, $depth);
        }
        if ($impl instanceof Expr\ArrowFunction) {
            return $this->values($impl->expr, $context, $file, [], [], $depth);
        }
        if (($impl instanceof Expr\StaticCall || $impl instanceof Expr\MethodCall) && $impl->isFirstClassCallable()) {
            return $this->method($this->callableClass($impl, $context), $impl->name, $depth);
        }
        if ($impl instanceof Expr\Array_ && count($impl->items) === 2) {
            $owner = $this->arrayCallableClass($impl->items[0]->value, $context);
            $name = $impl->items[1]->value;
            if ($owner !== null && $name instanceof String_) {
                return $this->method($owner, new Node\Identifier($name->value), $depth);
            }
        }
        $class = $this->className($impl, $context);
        if ($class === null) {
            throw new Unreadable('the implementation is not a class constant, a string, a closure or a callable');
        }

        return [$class => $this->site($file, $impl)];
    }

    /** @return array<string, Site> */
    private function method(?string $owner, Node\Identifier|Expr|null $name, int $depth): array
    {
        if ($owner === null || !$name instanceof Node\Identifier) {
            throw new Unreadable('the callable does not name a class and a method');
        }
        $found = $this->source->resolveMethod($owner, $name->toString());
        if ($found->method === null || $found->method->node->stmts === null) {
            throw new Unreadable("the callable {$owner}::{$name->toString()} has no body in the app");
        }
        $ref = $found->method;

        return $this->returned($ref->node->stmts, $owner, $ref->file, $depth + 1);
    }

    private function callableClass(Expr\StaticCall|Expr\MethodCall $call, string $context): ?string
    {
        if ($call instanceof Expr\MethodCall) {
            return $call->var instanceof Expr\Variable && $call->var->name === 'this' ? $context : null;
        }

        return $call->class instanceof Node\Name ? $this->named($call->class, $context) : null;
    }

    private function arrayCallableClass(Expr $e, string $context): ?string
    {
        if ($e instanceof Expr\Variable && $e->name === 'this') {
            return $context;
        }

        return $this->className($e, $context);
    }

    private function named(Node\Name $name, string $context): ?string
    {
        return match (strtolower($name->toString())) {
            'self', 'static' => $context,
            'parent' => $this->source->findClass($context)?->parent,
            default => $name->toString(),
        };
    }

    /**
     * @param array<Stmt> $stmts
     * @return array<string, Site>
     */
    private function returned(array $stmts, string $context, string $file, int $depth): array
    {
        $body = new FunctionBody($stmts);
        if ($body->returns === []) {
            throw new Unreadable('the callable returns nothing');
        }
        $classes = [];
        foreach ($body->returns as $return) {
            if ($return->expr === null) {
                throw new Unreadable('the callable has a return without a value');
            }
            $classes += $this->values($return->expr, $context, $file, $body->assignments, [], $depth);
        }

        return $classes;
    }

    /**
     * The classes an expression can evaluate to.
     *
     * @param array<string, list<Expr>> $assignments variable name => every right-hand side in the body
     * @param array<string, true> $tracing variables being followed, against a cycle
     * @return array<string, Site>
     */
    private function values(Expr $e, string $context, string $file, array $assignments, array $tracing, int $depth): array
    {
        switch (true) {
            case $e instanceof Expr\New_:
                if (!$e->class instanceof Node\Name) {
                    throw new Unreadable('new of a class that is not a plain name');
                }
                $class = $this->named($e->class, $context);
                if ($class === null) {
                    throw new Unreadable('new of a class that cannot be named');
                }

                return [$class => $this->site($file, $e)];

            case $e instanceof Expr\ClassConstFetch:
                $class = $this->className($e, $context);
                if ($class === null) {
                    throw new Unreadable('a class constant that is not known');
                }

                return [$class => $this->site($file, $e)];

            case $e instanceof Expr\Ternary:
                $found = $e->if === null ? $this->values($e->cond, $context, $file, $assignments, $tracing, $depth) : $this->values($e->if, $context, $file, $assignments, $tracing, $depth);

                return $found + $this->values($e->else, $context, $file, $assignments, $tracing, $depth);

            case $e instanceof Expr\BinaryOp\Coalesce:
                return $this->values($e->left, $context, $file, $assignments, $tracing, $depth)
                    + $this->values($e->right, $context, $file, $assignments, $tracing, $depth);

            case $e instanceof Expr\Match_:
                $found = [];
                foreach ($e->arms as $arm) {
                    if (!$arm->body instanceof Expr\Throw_) {
                        $found += $this->values($arm->body, $context, $file, $assignments, $tracing, $depth);
                    }
                }

                return $found;

            case $e instanceof Expr\Assign:
                return $this->values($e->expr, $context, $file, $assignments, $tracing, $depth);

            case $e instanceof Expr\Variable:
                $name = is_string($e->name) ? $e->name : null;
                if ($name === null || !isset($assignments[$name]) || isset($tracing[$name])) {
                    throw new Unreadable('a variable that is not assigned a class in the body');
                }
                $found = [];
                foreach ($assignments[$name] as $rhs) {
                    $found += $this->values($rhs, $context, $file, $assignments, $tracing + [$name => true], $depth);
                }

                return $found;

            case $e instanceof Expr\FuncCall && $e->name instanceof Node\Name && in_array(strtolower($e->name->toString()), ['app', 'resolve'], true):
                return $this->made($e->args, $context, $file, $e);

            case ($e instanceof Expr\MethodCall || $e instanceof Expr\StaticCall) && $this->isMake($e):
                return $this->made($e->args, $context, $file, $e);
        }

        throw new Unreadable('an expression that is not followed: ' . $e->getType());
    }

    private function isMake(Expr\MethodCall|Expr\StaticCall $call): bool
    {
        if (!$call->name instanceof Node\Identifier || !in_array($call->name->toString(), ['make', 'makeWith'], true)) {
            return false;
        }

        return $call instanceof Expr\MethodCall
            ? $call->var instanceof Expr\Variable || BindingSyntax::isApp($call->var)
            : $call->class instanceof Node\Name && BindingSyntax::isAppFacade($call->class);
    }

    /**
     * @param array<Node\Arg|Node\VariadicPlaceholder> $args
     * @return array<string, Site>
     */
    private function made(array $args, string $context, string $file, Expr $at): array
    {
        $first = $args[0] ?? null;
        if (!$first instanceof Node\Arg) {
            throw new Unreadable('make() or app() without a class argument');
        }

        return $this->classNames($first->value, $context, $file, $at);
    }

    /** @return array<string, Site> */
    private function classNames(Expr $e, string $context, string $file, Expr $at): array
    {
        if ($e instanceof Expr\Ternary) {
            $found = $e->if === null ? $this->classNames($e->cond, $context, $file, $at) : $this->classNames($e->if, $context, $file, $at);

            return $found + $this->classNames($e->else, $context, $file, $at);
        }
        if ($e instanceof Expr\BinaryOp\Coalesce) {
            return $this->classNames($e->left, $context, $file, $at) + $this->classNames($e->right, $context, $file, $at);
        }
        if ($e instanceof Expr\Match_) {
            $found = [];
            foreach ($e->arms as $arm) {
                if (!$arm->body instanceof Expr\Throw_) {
                    $found += $this->classNames($arm->body, $context, $file, $at);
                }
            }

            return $found;
        }
        $class = $this->className($e, $context);
        if ($class === null) {
            throw new Unreadable('make() or app() of a class that is not a constant');
        }

        return [$class => $this->site($file, $at)];
    }

    private function site(string $file, Node $at): Site
    {
        return new Site($this->source->relative($file), $at->getStartLine());
    }
}
