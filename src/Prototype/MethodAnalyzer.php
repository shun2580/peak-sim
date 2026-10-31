<?php

declare(strict_types=1);

namespace PeakSim\Prototype;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Walks a method body, infers the few variable types it needs, and records where queries are issued and
 * how many loops over database rows enclose them. Each method is summarised once and the summary is
 * reused at every call site. Anything it cannot follow becomes a gap, never a guess.
 */
final class MethodAnalyzer
{
    public const MAX_DEPTH = 20;

    /** Builder calls that keep the builder and change nothing the prototype tracks. */
    private const BUILDER_PASSTHROUGH = ['where', 'whereIn', 'whereNull', 'whereNotNull', 'orderBy', 'orderByDesc', 'latest', 'oldest', 'select'];

    private const BUILDER_ROW = ['first', 'find', 'firstOrFail', 'findOrFail', 'sole'];

    private const BUILDER_SCALAR = ['count', 'exists', 'sum', 'max', 'min', 'avg'];

    /** Collection methods that call a closure once per element, i.e. a loop. */
    private const COLLECTION_LOOPS = ['each', 'map', 'filter', 'reject', 'flatMap', 'mapWithKeys'];

    /** @var array<string, Summary> */
    private array $summaries = [];

    /** @var array<string, true> */
    private array $inProgress = [];

    public function __construct(
        private readonly ClassIndex $classes,
        private readonly ExportReader $export,
        private readonly BindingResolver $bindings,
    ) {
    }

    public function summarize(string $class, string $method, int $level = 0): Summary
    {
        $key = $class . '::' . $method;
        if (isset($this->summaries[$key])) {
            return $this->summaries[$key];
        }

        $summary = new Summary();
        $found = $this->classes->findMethod($class, $method);
        if ($found === null) {
            // A vendor class or a method from a trait / framework base: outside app/, not followed.
            if ($this->classes->fileFor($class) !== null) {
                $file = (string) $this->classes->fileFor($class);
                $summary->gap($file, 1, "method {$class}::{$method}() not found in app code");
            }

            return $summary;
        }

        $file = $found['file'];
        $node = $found['node'];
        if (isset($this->inProgress[$key])) {
            $summary->gap($file, $node->getStartLine(), "recursion into {$key}, not followed");

            return $summary;
        }
        if ($level >= self::MAX_DEPTH) {
            $summary->gap($file, $node->getStartLine(), 'stopped at the call depth limit of ' . self::MAX_DEPTH);

            return $summary;
        }

        $this->inProgress[$key] = true;
        $summary->trace($file, $node->getStartLine(), "enter {$class}::{$method}()");
        $scope = new Scope($file, $class, $summary, $level);
        $scope->vars['this'] = Type::object($class);
        foreach ($node->params as $param) {
            if ($param->var instanceof Expr\Variable && is_string($param->var->name)) {
                $scope->vars[$param->var->name] = $this->declaredType($param->type);
            }
        }
        foreach ($node->stmts ?? [] as $stmt) {
            $this->walk($stmt, $scope);
        }
        $summary->returnType ??= $this->declaredType($node->returnType);
        unset($this->inProgress[$key]);

        return $this->summaries[$key] = $summary;
    }

    private function walk(Node $node, Scope $scope): ?Type
    {
        return match (true) {
            $node instanceof Stmt\Foreach_ => $this->foreach($node, $scope),
            $node instanceof Stmt\Return_ => $this->returnStmt($node, $scope),
            $node instanceof Expr\Assign => $this->assign($node, $scope),
            $node instanceof Expr\Variable => is_string($node->name) ? ($scope->vars[$node->name] ?? null) : null,
            $node instanceof Expr\PropertyFetch => $this->propertyFetch($node, $scope),
            $node instanceof Expr\MethodCall => $this->methodCall($node, $scope),
            $node instanceof Expr\StaticCall => $this->staticCall($node, $scope),
            // A closure that is not a collection callback is not run here (not followed, not counted).
            $node instanceof Expr\Closure, $node instanceof Expr\ArrowFunction => null,
            $node instanceof Stmt\For_, $node instanceof Stmt\While_, $node instanceof Stmt\Do_ => $this->unsupportedLoop($node, $scope),
            default => $this->walkChildren($node, $scope),
        };
    }

    private function walkChildren(Node $node, Scope $scope): ?Type
    {
        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->$name;
            foreach (is_array($child) ? $child : [$child] as $item) {
                if ($item instanceof Node) {
                    $this->walk($item, $scope);
                }
            }
        }

        return null;
    }

    private function foreach(Stmt\Foreach_ $node, Scope $scope): ?Type
    {
        $source = $this->walk($node->expr, $scope);
        $elem = $source?->kind === Type::COLLECTION ? $source->elem : null;
        $before = count($scope->summary->events);
        $inner = $elem !== null ? $scope->loop($this->export->table((string) $elem->class)) : $scope->sameLevel();
        if ($node->valueVar instanceof Expr\Variable && is_string($node->valueVar->name)) {
            $inner->vars[$node->valueVar->name] = $elem;
        }
        foreach ($node->stmts as $stmt) {
            $this->walk($stmt, $inner);
        }
        if ($elem === null && count($scope->summary->events) > $before) {
            $scope->summary->gap($scope->file, $node->getStartLine(), 'loop over a value of unknown type that contains queries: its size is not known');
        }

        return null;
    }

    private function unsupportedLoop(Node $node, Scope $scope): ?Type
    {
        $before = count($scope->summary->events);
        $this->walkChildren($node, $scope);
        if (count($scope->summary->events) > $before) {
            $scope->summary->gap($scope->file, $node->getStartLine(), 'for / while loop with queries: not supported by the prototype');
        }

        return null;
    }

    private function returnStmt(Stmt\Return_ $node, Scope $scope): ?Type
    {
        $type = $node->expr !== null ? $this->walk($node->expr, $scope) : null;
        $scope->summary->returnType ??= $type;

        return null;
    }

    private function assign(Expr\Assign $node, Scope $scope): ?Type
    {
        $type = $this->walk($node->expr, $scope);
        if ($node->var instanceof Expr\Variable && is_string($node->var->name)) {
            $scope->vars[$node->var->name] = $type;
        } else {
            $this->walk($node->var, $scope);
        }

        return $type;
    }

    private function propertyFetch(Expr\PropertyFetch $node, Scope $scope): ?Type
    {
        $base = $this->walk($node->var, $scope);
        if (!$node->name instanceof Node\Identifier || $base === null) {
            return null;
        }
        $name = $node->name->toString();

        if ($base->kind === Type::OBJECT) {
            $class = $this->classes->propertyType((string) $base->class, $name);

            return $class !== null ? Type::object($class) : null;
        }
        if ($base->kind !== Type::MODEL) {
            return null;
        }
        $relation = $this->export->model((string) $base->class)['relations'][$name] ?? null;
        if ($relation === null) {
            return null; // a plain attribute
        }

        $nested = [];
        foreach ($base->eager as $loaded) {
            if (str_starts_with($loaded, $name . '.')) {
                $nested[] = substr($loaded, strlen($name) + 1);
            }
        }
        $related = Type::model($relation['related'], $nested);
        if (!in_array($name, $base->eager, true)) {
            $scope->summary->events[] = new Event(
                Event::LAZY,
                $scope->depth,
                $scope->tables,
                $this->export->table($relation['related']),
                $relation['many'],
                $scope->file,
                $node->getStartLine(),
                "lazy load of relation {$this->shortName((string) $base->class)}::{$name}",
            );
        }

        return $relation['many'] ? Type::collection($related) : $related;
    }

    private function methodCall(Expr\MethodCall $node, Scope $scope): ?Type
    {
        $receiver = $this->walk($node->var, $scope);
        if (!$node->name instanceof Node\Identifier) {
            $this->walkArgs($node->args, $scope);
            $scope->summary->gap($scope->file, $node->getStartLine(), 'dynamic method name: not followed');

            return null;
        }
        $name = $node->name->toString();

        return match ($receiver?->kind) {
            Type::BUILDER => $this->builderCall($receiver, $name, $node, $scope),
            Type::COLLECTION => $this->collectionCall($receiver, $name, $node, $scope),
            Type::OBJECT => $this->objectCall($receiver, $name, $node, $scope),
            Type::MODEL => $this->unsupported($node, $scope, "{$this->shortName((string) $receiver->class)}::{$name}() on a model"),
            default => $this->walkArgs($node->args, $scope),
        };
    }

    private function staticCall(Expr\StaticCall $node, Scope $scope): ?Type
    {
        $class = $node->class instanceof Node\Name ? $node->class->toString() : null;
        if ($class === null || !$this->export->isModel($class) || !$node->name instanceof Node\Identifier) {
            return $this->walkArgs($node->args, $scope);
        }
        $name = $node->name->toString();
        if ($name === 'all') {
            $this->walkArgs($node->args, $scope);
            $this->query($scope, $node, $class, true, "{$this->shortName($class)}::all()");

            return Type::collection(Type::model($class));
        }

        return $this->builderCall(Type::builder($class), $name, $node, $scope);
    }

    private function builderCall(Type $builder, string $name, Expr\MethodCall|Expr\StaticCall $node, Scope $scope): ?Type
    {
        $class = (string) $builder->class;
        $short = $this->shortName($class);

        if ($name === 'with') {
            $relations = $this->relationNames($node, $scope);
            foreach ($relations as $relation) {
                $related = $this->export->model($class)['relations'][explode('.', $relation)[0]]['related'] ?? null;
                $scope->summary->events[] = new Event(
                    Event::EAGER,
                    $scope->depth,
                    $scope->tables,
                    $related !== null ? $this->export->table($related) : null,
                    false,
                    $scope->file,
                    $node->getStartLine(),
                    "eager load of {$short}::{$relation} with with()",
                );
            }

            return $builder->withEager($relations);
        }
        $this->walkArgs($node->args, $scope);
        if (in_array($name, self::BUILDER_PASSTHROUGH, true)) {
            return $builder;
        }
        if ($name === 'get') {
            $this->query($scope, $node, $class, true, "{$short} query ending in get() with no limit");

            return Type::collection(Type::model($class, $builder->eager));
        }
        if (in_array($name, self::BUILDER_ROW, true)) {
            $this->query($scope, $node, $class, false, "{$short} query ending in {$name}()");

            return Type::model($class, $builder->eager);
        }
        if (in_array($name, self::BUILDER_SCALAR, true)) {
            $this->query($scope, $node, $class, false, "{$short} query ending in {$name}()");

            return null;
        }

        return $this->unsupported($node, $scope, "{$short}::{$name}() on a query builder");
    }

    private function collectionCall(Type $collection, string $name, Expr\MethodCall $node, Scope $scope): ?Type
    {
        $elem = $collection->elem;
        if ($elem === null || !in_array($name, self::COLLECTION_LOOPS, true)) {
            $this->walkArgs($node->args, $scope);

            return $name === 'first' ? $elem : null;
        }

        $inner = $scope->loop($this->export->table((string) $elem->class));
        foreach ($node->args as $arg) {
            if (!$arg instanceof Node\Arg) {
                continue;
            }
            $callback = $arg->value;
            if ($callback instanceof Expr\ArrowFunction || $callback instanceof Expr\Closure) {
                $body = $inner->sameLevel();
                $this->bindCallbackParams($callback, $elem, $body, $scope);
                if ($callback instanceof Expr\ArrowFunction) {
                    $this->walk($callback->expr, $body);
                } else {
                    foreach ($callback->stmts as $stmt) {
                        $this->walk($stmt, $body);
                    }
                }
            } else {
                $this->walk($callback, $scope);
            }
        }

        return in_array($name, ['filter', 'reject'], true) ? $collection : null;
    }

    private function bindCallbackParams(Expr\ArrowFunction|Expr\Closure $callback, Type $elem, Scope $body, Scope $outer): void
    {
        if ($callback instanceof Expr\Closure) {
            $body->vars = [];
            foreach ($callback->uses as $use) {
                if (is_string($use->var->name)) {
                    $body->vars[$use->var->name] = $outer->vars[$use->var->name] ?? null;
                }
            }
        }
        $first = $callback->params[0] ?? null;
        if ($first !== null && $first->var instanceof Expr\Variable && is_string($first->var->name)) {
            $declared = $this->declaredType($first->type);
            // The element type carries the eager-loaded relations; a declared model type does not.
            $body->vars[$first->var->name] = $declared !== null && $declared->class !== $elem->class ? $declared : $elem;
        }
    }

    private function objectCall(Type $object, string $name, Expr\MethodCall $node, Scope $scope): ?Type
    {
        $this->walkArgs($node->args, $scope);
        $class = (string) $object->class;

        $targets = [];
        if ($this->classes->isInterface($class)) {
            foreach ($this->bindings->resolve($class) as $binding) {
                $scope->summary->trace($binding['file'], $binding['line'], "binding {$class} => {$binding['impl']}");
                $targets[] = $binding['impl'];
            }
            if ($targets === []) {
                $scope->summary->gap($scope->file, $node->getStartLine(), "no ServiceProvider binding found for interface {$class}");

                return null;
            }
            if (count($targets) > 1) {
                $scope->summary->trace($scope->file, $node->getStartLine(), "{$class} has " . count($targets) . ' bindings: all analysed, worst case adopted');
            }
        } else {
            $targets[] = $class;
        }

        $returned = null;
        foreach ($targets as $target) {
            $callee = $this->summarize($target, $name, $scope->level + 1);
            $scope->summary->absorb($callee, $scope->depth, $scope->tables);
            $returned ??= $callee->returnType;
        }

        return $returned;
    }

    private function query(Scope $scope, Node $node, string $class, bool $unbounded, string $note): void
    {
        $scope->summary->events[] = new Event(
            Event::QUERY,
            $scope->depth,
            $scope->tables,
            $this->export->table($class),
            $unbounded,
            $scope->file,
            $node->getStartLine(),
            $note,
        );
    }

    private function unsupported(Node $node, Scope $scope, string $what): ?Type
    {
        $this->walkArgs($node->args ?? [], $scope);
        $scope->summary->gap($scope->file, $node->getStartLine(), "not supported by the prototype: {$what}");

        return null;
    }

    /** @param array<Node\Arg|Node\VariadicPlaceholder> $args */
    private function walkArgs(array $args, Scope $scope): ?Type
    {
        foreach ($args as $arg) {
            if ($arg instanceof Node\Arg) {
                $this->walk($arg->value, $scope);
            }
        }

        return null;
    }

    /** @return list<string> relation names given to with(): 'a', ['a', 'b'] or ['a' => fn ...] */
    private function relationNames(Expr\MethodCall|Expr\StaticCall $node, Scope $scope): array
    {
        $names = [];
        foreach ($node->args as $arg) {
            if (!$arg instanceof Node\Arg) {
                continue;
            }
            $value = $arg->value;
            $items = $value instanceof Expr\Array_ ? $value->items : [$value];
            foreach ($items as $item) {
                $expr = $item instanceof Expr\ArrayItem ? ($item->key ?? $item->value) : $item;
                if ($expr instanceof Node\Scalar\String_) {
                    $names[] = $expr->value;
                } else {
                    $scope->summary->gap($scope->file, $node->getStartLine(), 'with() argument is not a string literal: relation names not known');
                }
            }
        }

        return $names;
    }

    private function declaredType(?Node $type): ?Type
    {
        if ($type instanceof Node\NullableType) {
            $type = $type->type;
        }
        if (!$type instanceof Node\Name) {
            return null;
        }
        $class = $type->toString();
        if ($this->export->isModel($class)) {
            return Type::model($class);
        }

        return $this->classes->fileFor($class) !== null ? Type::object($class) : null;
    }

    private function shortName(string $class): string
    {
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
