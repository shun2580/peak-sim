<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Engine;

use PeakSim\Analysis\Contracts\CallSite;
use PeakSim\Analysis\Type\ClosureT;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\ObjectT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\ShapeT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Analysis\Type\UnionT;
use PeakSim\Analysis\Type\UnknownT;
use PeakSim\Binding\BindingResultKind;
use PeakSim\Binding\ProviderBindingTable;
use PeakSim\Model\Cardinality;
use PeakSim\Model\GapCode;
use PeakSim\Model\Site;
use PeakSim\Source\MethodMiss;
use PeakSim\Source\MethodRef;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\EnumCase;

/**
 * Gives a call its meaning. Handlers are asked first, in priority order; a call nobody takes is a method of an
 * app class (summarised and absorbed), or else a gap. A gap here is never silent: the result is an UnknownT.
 *
 * What a handler sees in a CallSite:
 *  - method call:     node = MethodCall | NullsafeMethodCall, receiver = the receiver's type, name = method
 *  - static call:     node = StaticCall, staticClass = the class (self / static / parent already resolved)
 *  - function call:   node = FuncCall, name = the function name as written
 *  - new:             node = New_, staticClass = the class, name = '__construct'
 *  - property read:   node = PropertyFetch | NullsafePropertyFetch, receiver = the base's type, name = the property
 */
final class CallResolver
{
    /** Collection methods that add to the collection they are called on. */
    private const COLLECTING_METHODS = ['push', 'add', 'prepend', 'put'];

    /** Methods that load relations or aggregates onto the value they are called on (Collection and Model). */
    private const LOADING_METHODS = ['load', 'loadmissing', 'loadcount', 'loadsum', 'loadmax', 'loadmin', 'loadavg', 'loadexists', 'loadmorph'];

    public function __construct(
        private readonly Engine $engine,
        private readonly Walker $walker,
    ) {
    }

    /**
     * Put the arguments of a call into the parameters' variables.
     *
     * @param array<Node\Param> $params
     * @param list<Type> $args
     * @param array<string, Type> $named
     */
    public function bindParameters(array $params, array $args, array $named, Scope $scope, MethodRun $run, bool $closure): void
    {
        foreach ($params as $i => $param) {
            if (!$param->var instanceof Expr\Variable || !is_string($param->var->name)) {
                continue;
            }
            $name = $param->var->name;
            if ($param->variadic) {
                $rest = array_slice($args, $i);
                $type = new ListT($rest === [] ? new PlainT() : TypeMerge::joinAll($rest)->type, Cardinality::bounded());
            } else {
                $type = $args[$i] ?? $named[$name] ?? null;
                if ($type === null && $param->default !== null) {
                    $type = $this->walker->evaluate($param->default, $scope, $run);
                }
                // A route parameter or an argument the caller left out: whatever the declaration says, else plain.
                $type ??= $this->engine->types()->fromDeclaration($param->type, $scope->class, $scope->declClass, $this->engine->parentOf(...)) ?? new PlainT();
            }
            $scope->set($name, $type, count($run->frames));
        }
    }

    // ----- expressions ------------------------------------------------------------------------------------

    public function methodCall(Expr\MethodCall|Expr\NullsafeMethodCall $n, Scope $scope, MethodRun $run): Type
    {
        $recv = $this->walker->evaluate($n->var, $scope, $run);
        [$args, $eval] = $this->prepareArgs($n->args, $scope, $run);
        if ($n->isFirstClassCallable()) {
            return $this->gap($run, $scope, GapCode::UNRESOLVED_CALL, $n, 'first-class callable syntax is not followed');
        }
        if (!$n->name instanceof Node\Identifier) {
            $this->evaluateAll($args, $eval);

            return $this->gap($run, $scope, GapCode::DYNAMIC_METHOD_NAME, $n, 'the method name is not a literal');
        }

        $name = $n->name->toString();
        $result = $this->onReceiver($n, $recv, $name, $args, $eval, $scope, $run);
        $this->rebindAfterLoad($n, $recv, $name, $result, $scope, $run);

        return $this->collectsIntoItsReceiver($n, $recv, $name, $args, $eval, $scope, $run) ?? $result;
    }

    /**
     * `$orders->load('customer')` changes $orders, not just what the call returns: from here on the variable holds
     * the type the handler gave back (the same kind of value, with the relations now loaded). Flow-sensitive by
     * variable; where branches meet, TypeMerge keeps only what every branch loaded.
     */
    private function rebindAfterLoad(Expr\MethodCall|Expr\NullsafeMethodCall $n, Type $recv, string $name, Type $result, Scope $scope, MethodRun $run): void
    {
        if (!$n->var instanceof Expr\Variable || !is_string($n->var->name) || $n->var->name === 'this') {
            return;
        }
        if (!in_array(strtolower($name), self::LOADING_METHODS, true)) {
            return;
        }
        if (($recv instanceof ListT && $result instanceof ListT) || ($recv instanceof ModelT && $result instanceof ModelT)) {
            $scope->set($n->var->name, $result, count($run->frames));
        }
    }

    /**
     * `$rows->push($v)` (add / prepend / put alike) and `$rows = $rows->merge($more)` (concat) in a loop below where
     * $rows was made are an append, as `$rows[] = $v` is. Null when the call is not one; else the list now in $rows.
     *
     * @param list<Node\Arg> $args
     * @param \Closure(int): Type $eval
     */
    private function collectsIntoItsReceiver(Expr\MethodCall|Expr\NullsafeMethodCall $n, Type $recv, string $name, array $args, \Closure $eval, Scope $scope, MethodRun $run): ?Type
    {
        if (!$recv instanceof ListT || !$n->var instanceof Expr\Variable) {
            return null;
        }
        $lower = strtolower($name);
        $mutates = in_array($lower, self::COLLECTING_METHODS, true);
        if (!$mutates && !($n instanceof Expr\MethodCall && in_array($lower, ['merge', 'concat'], true) && $this->walker->isSelfMerge($n))) {
            return null;
        }
        $result = null;
        if ($mutates) {
            // put($key, $value) is the one with the value second.
            $valueAt = $lower === 'put' ? [1] : ($lower === 'push' ? array_keys($args) : [0]);
            foreach ($valueAt as $i) {
                if (!isset($args[$i])) {
                    continue;
                }
                $value = $eval($i);
                $unpacked = $args[$i]->unpack && $value instanceof ListT;
                $result = $this->walker->collectOnVariable($n->var, $unpacked ? $value->elem : $value, $value, $n, $scope, $run) ?? $result;
            }

            return $result;
        }
        $other = isset($args[0]) ? $eval(0) : new PlainT();
        $element = match (true) {
            $other instanceof ListT => $other->elem,
            $other instanceof ShapeT => TypeMerge::joinAll(array_values($other->entries))->type,
            default => $other,
        };

        return $this->walker->collectOnVariable($n->var, $element, $other, $n, $scope, $run);
    }

    public function staticCall(Expr\StaticCall $n, Scope $scope, MethodRun $run): Type
    {
        [$args, $eval] = $this->prepareArgs($n->args, $scope, $run);
        if ($n->isFirstClassCallable()) {
            return $this->gap($run, $scope, GapCode::UNRESOLVED_CALL, $n, 'first-class callable syntax is not followed');
        }
        if (!$n->class instanceof Node\Name) {
            $this->walker->evaluate($n->class, $scope, $run);
            $this->evaluateAll($args, $eval);

            return $this->gap($run, $scope, GapCode::DYNAMIC_CLASS_NAME, $n, 'the class name is not a literal');
        }
        if (!$n->name instanceof Node\Identifier) {
            $this->evaluateAll($args, $eval);

            return $this->gap($run, $scope, GapCode::DYNAMIC_METHOD_NAME, $n, 'the method name is not a literal');
        }
        $class = $this->className($n->class, $scope);
        if ($class === null) {
            $this->evaluateAll($args, $eval);

            return $this->gap($run, $scope, GapCode::UNRESOLVED_CALL, $n, 'cannot tell which class ' . $n->class->toString() . ':: means here');
        }
        $name = $n->name->toString();

        $handled = $this->dispatch(new CallSite($n, null, $class, $name, $args, $eval), $scope, $run);
        if ($handled !== null) {
            return $handled;
        }
        [$positional, $named] = $this->evaluateAll($args, $eval);
        $special = in_array(strtolower($n->class->toString()), ['self', 'static', 'parent'], true);

        return $this->callOnClass($class, $name, $positional, $named, $n, $special ? $scope->selfType : null, $scope, $run, $special ? $scope->class : null);
    }

    public function funcCall(Expr\FuncCall $n, Scope $scope, MethodRun $run): Type
    {
        [$args, $eval] = $this->prepareArgs($n->args, $scope, $run);
        if ($n->isFirstClassCallable()) {
            return $this->gap($run, $scope, GapCode::UNRESOLVED_CALL, $n, 'first-class callable syntax is not followed');
        }
        if (!$n->name instanceof Node\Name) {
            $callee = $this->walker->evaluate($n->name, $scope, $run);
            [$positional] = $this->evaluateAll($args, $eval);
            if ($callee instanceof ClosureT) {
                return $this->walker->invokeClosure($callee, $positional, $run);
            }
            if ($callee instanceof UnknownT) {
                return $callee;
            }

            return $this->gap($run, $scope, GapCode::DYNAMIC_METHOD_NAME, $n, 'the function being called is not a known closure');
        }
        $name = ltrim($n->name->toString(), '\\');

        $handled = $this->dispatch(new CallSite($n, null, null, $name, $args, $eval), $scope, $run);
        if ($handled !== null) {
            return $handled;
        }
        $this->evaluateAll($args, $eval);

        return $this->gap($run, $scope, GapCode::UNRESOLVED_CALL, $n, "function {$name}() is not modelled");
    }

    public function newExpr(Expr\New_ $n, Scope $scope, MethodRun $run): Type
    {
        [$args, $eval] = $this->prepareArgs($n->args, $scope, $run);
        if (!$n->class instanceof Node\Name) {
            $this->evaluateAll($args, $eval);

            return $n->class instanceof Node\Stmt\Class_
                ? $this->gap($run, $scope, GapCode::NEW_UNKNOWN_CLASS, $n, 'anonymous class is not followed')
                : $this->gap($run, $scope, GapCode::DYNAMIC_CLASS_NAME, $n, 'the class name is not a literal');
        }
        $class = $this->className($n->class, $scope);
        if ($class === null) {
            $this->evaluateAll($args, $eval);

            return $this->gap($run, $scope, GapCode::NEW_UNKNOWN_CLASS, $n, 'cannot tell which class ' . $n->class->toString() . ' means here');
        }

        $handled = $this->dispatch(new CallSite($n, null, $class, '__construct', $args, $eval), $scope, $run);
        if ($handled !== null) {
            return $handled;
        }
        [$positional, $named] = $this->evaluateAll($args, $eval);
        if ($this->engine->models()->isModel($class)) {
            return new ModelT($class);
        }
        if ($this->engine->source()->findClass($class) === null) {
            return $this->gap($run, $scope, GapCode::NEW_UNKNOWN_CLASS, $n, "class {$class} is not in the app and is not modelled");
        }
        $self = new ObjectT($class);
        $constructor = $this->engine->source()->resolveMethod($class, '__construct');
        if ($constructor->method !== null) {
            $this->invoke($constructor->method, $class, $self, $positional, $named, $n, $scope, $run);
        }

        return $self;
    }

    public function propertyFetch(Expr\PropertyFetch|Expr\NullsafePropertyFetch $n, Scope $scope, MethodRun $run): Type
    {
        $recv = $this->walker->evaluate($n->var, $scope, $run);
        if (!$n->name instanceof Node\Identifier) {
            $this->walker->evaluate($n->name, $scope, $run);

            return $this->gap($run, $scope, GapCode::UNRESOLVED_CALL, $n, 'the property name is not a literal');
        }

        $name = $n->name->toString();
        if ($this->isCaseFetch($n->var, $name, $scope)) {
            // `Foo::BAR->value`: the receiver is a case the enum declares (D45). Walker::constant types it as a plain value.
            return new PlainT();
        }

        return $this->propertyOf($n, $recv, $name, $scope, $run);
    }

    /** D45: `Foo::BAR->name` / `->value` where `Foo` is an app enum and `BAR` is one of its declared cases. */
    private function isCaseFetch(Expr $base, string $property, Scope $scope): bool
    {
        if (!$base instanceof Expr\ClassConstFetch || !$base->class instanceof Node\Name || !$base->name instanceof Node\Identifier) {
            return false;
        }
        $class = $this->className($base->class, $scope);
        $enum = $class === null ? null : $this->enumNode($class);
        if ($enum === null || !$this->isEnumRead($enum, $property)) {
            return false;
        }
        foreach ($enum->stmts as $stmt) {
            if ($stmt instanceof EnumCase && $stmt->name->toString() === $base->name->toString()) {
                return true;
            }
        }

        return false;
    }

    private function enumNode(string $class): ?Enum_
    {
        $info = $this->engine->source()->findClass($class);

        return $info !== null && $info->kind === 'enum' && $info->node instanceof Enum_ ? $info->node : null;
    }

    /** Every case has `name`; only a backed enum has `value` (D45). */
    private function isEnumRead(Enum_ $enum, string $property): bool
    {
        return $property === 'name' || ($property === 'value' && $enum->scalarType !== null);
    }

    // ----- calls into app code -----------------------------------------------------------------------------

    /**
     * Call $class::$method with already evaluated arguments: resolve it (through bindings for an interface),
     * summarise it, and take the summary in at the current loops.
     *
     * @param list<Type> $args
     * @param array<string, Type> $named
     * @param string|null $receiver the class self:: and static:: mean in the callee, when it is not $class (parent:: calls)
     */
    public function callOnClass(string $class, string $method, array $args, array $named, Node $site, ?Type $self, Scope $scope, MethodRun $run, ?string $receiver = null): Type
    {
        $source = $this->engine->source();
        $class = ltrim($class, '\\');
        $info = $source->findClass($class);
        if ($info === null) {
            // A vendor type the app binds to its own class (or to another vendor type) is followed; unbound, it is not modelled.
            if ($this->engine->bindings()->resolve($class, $scope->class)->kind !== BindingResultKind::None) {
                return $this->throughBindings($class, $method, $args, $named, $site, $self, $scope, $run);
            }

            return $this->gap($run, $scope, GapCode::VENDOR_CALL_NOT_MODELLED, $site, "{$class}::{$method}() belongs to a vendor class and is not modelled");
        }

        $resolution = $source->resolveMethod($class, $method);
        $ref = $resolution->method;
        $hasBody = $ref !== null && $info->kind !== 'interface' && $ref->node->stmts !== null;
        if ($hasBody) {
            return $this->invoke($ref, $receiver ?? $class, $self ?? $this->engine->types()->forClass($class), $args, $named, $site, $scope, $run);
        }
        if ($info->kind === 'interface' || $info->isAbstract || $ref !== null) {
            return $this->throughBindings($class, $method, $args, $named, $site, $self, $scope, $run);
        }

        $isModel = $this->engine->models()->isModel($class);
        [$code, $why] = match ($resolution->miss) {
            MethodMiss::VendorParent => $isModel
                ? [GapCode::UNKNOWN_BUILDER_METHOD, "{$class}::{$method}() is not declared in the model and is not a known builder method"]
                : [GapCode::VENDOR_PARENT, "{$class}::{$method}() is not declared in the app; it may come from a vendor parent class"],
            MethodMiss::TraitConflict => [GapCode::TRAIT_CONFLICT, "{$class}::{$method}() is declared by more than one trait"],
            default => [GapCode::METHOD_NOT_FOUND, "{$class}::{$method}() was not found"],
        };

        return $this->gap($run, $scope, $code, $site, $why);
    }

    /**
     * @param list<Type> $args
     * @param array<string, Type> $named
     */
    private function throughBindings(string $abstract, string $method, array $args, array $named, Node $site, ?Type $self, Scope $scope, MethodRun $run): Type
    {
        $targets = [];
        $problems = [];
        $this->collectImplementations($abstract, $method, $site, $scope, $run, $targets, $problems, [$abstract => true], 0);

        $source = $this->engine->source();
        $outcomes = [];
        $results = [];
        foreach ($targets as $impl => $candidate) {
            $ref = $source->resolveMethod($impl, $method)->method;
            if ($ref === null) {
                continue;
            }
            $implSelf = $self instanceof ObjectT || $self === null ? new ObjectT($impl) : $self;
            $outcomes[$impl] = [$ref, $implSelf, $this->engine->summarize($ref, $impl, $implSelf, $args, $named, $run)];
            $run->trace[] = new Site($candidate->site->file, $candidate->site->line, "binding {$abstract} => {$impl}");
        }

        $computed = [];
        foreach ($outcomes as $impl => [, , $outcome]) {
            if ($outcome->refusal === null) {
                $computed[$impl] = $outcome->summary;
            }
        }
        $disagreement = CandidateComparison::disagreement($computed);
        if ($disagreement !== null) {
            (new EvalContextImpl($this->engine, $run, $scope))->assume(
                \PeakSim\Model\AssumptionCode::WORST_CASE_OF_CANDIDATES,
                $site,
                "{$abstract} has " . count($computed) . ' implementations that cost differently; the costliest is adopted',
                $disagreement['axes'],
                [...array_keys($computed), 'abstract' => $abstract, 'chosen' => $disagreement['chosen']],
            );
        }

        foreach ($outcomes as $impl => [$ref, , $outcome]) {
            $results[] = $this->take($outcome, $ref, $impl, $site, $scope, $run);
        }

        return TypeMerge::joinAll([...$results, ...$problems])->type;
    }

    /**
     * The concrete classes an abstract can be bound to, following a binding that points at another abstract
     * (A -> B -> Concrete). Anything that cannot be followed becomes a gap in $problems.
     *
     * @param array<string, \PeakSim\Binding\Candidate> $targets filled: concrete class => the candidate that named it
     * @param list<UnknownT> $problems
     * @param array<string, true> $visited
     */
    private function collectImplementations(string $abstract, string $method, Node $site, Scope $scope, MethodRun $run, array &$targets, array &$problems, array $visited, int $depth): void
    {
        $result = $this->engine->bindings()->resolve($abstract, $scope->class);
        if ($result->kind === BindingResultKind::None) {
            $problems[] = $this->gap($run, $scope, GapCode::NO_BINDING, $site, $depth === 0 ? "no binding found for {$abstract}" : "{$abstract} is bound to another abstract type that has no binding");

            return;
        }
        if ($result->kind === BindingResultKind::Unresolvable) {
            $reason = $result->reason ?? '';
            $decorated = str_starts_with($reason, ProviderBindingTable::REASON_DECORATED);
            $problems[] = $this->gap(
                $run,
                $scope,
                $decorated ? GapCode::BINDING_DECORATED : GapCode::UNRESOLVABLE_BINDING,
                $site,
                $decorated
                    ? "the binding of {$abstract} is decorated, so the implementation behind it is not known: " . trim(substr($reason, strlen(ProviderBindingTable::REASON_DECORATED)))
                    : "the binding of {$abstract} cannot be followed: " . $reason,
            );

            return;
        }

        $source = $this->engine->source();
        foreach ($result->candidates as $candidate) {
            $impl = ltrim($candidate->impl, '\\');
            $info = $source->findClass($impl);
            $ref = $info === null ? null : $source->resolveMethod($impl, $method)->method;
            $hasBody = $ref !== null && $info !== null && $info->kind !== 'interface' && $ref->node->stmts !== null;
            if ($hasBody) {
                $targets[$impl] ??= $candidate;
                continue;
            }
            if ($info !== null && ($info->kind === 'interface' || $info->isAbstract)) {
                if (isset($visited[$impl]) || $depth >= 10) {
                    $problems[] = $this->gap($run, $scope, GapCode::UNRESOLVABLE_BINDING, $site, "the bindings of {$abstract} lead back to {$impl}");
                    continue;
                }
                $this->collectImplementations($impl, $method, $site, $scope, $run, $targets, $problems, $visited + [$impl => true], $depth + 1);
                continue;
            }
            $problems[] = $this->gap($run, $scope, GapCode::METHOD_NOT_FOUND, $site, "{$impl}::{$method}() was not found (bound to {$abstract})");
        }
    }

    /**
     * @param list<Type> $args
     * @param array<string, Type> $named
     */
    private function invoke(MethodRef $ref, string $receiver, ?Type $self, array $args, array $named, Node $site, Scope $scope, MethodRun $run): Type
    {
        return $this->take($this->engine->summarize($ref, $receiver, $self, $args, $named, $run), $ref, $receiver, $site, $scope, $run);
    }

    private function take(SummaryOutcome $outcome, MethodRef $ref, string $receiver, Node $site, Scope $scope, MethodRun $run): Type
    {
        $what = $ref->owner . '::' . $ref->node->name->toString() . '()';
        if ($outcome->refusal !== null) {
            return $this->gap($run, $scope, $outcome->refusal, $site, match ($outcome->refusal) {
                GapCode::DEPTH_LIMIT => "call depth limit of {$this->engine->options()->maxDepth} reached before {$what}",
                GapCode::RECURSION => "recursion into {$what}: not followed",
                default => "analysis budget exhausted before {$what}",
            });
        }
        $run->absorb($outcome->summary, $outcome->deepest, $outcome->poisoned);

        return $outcome->summary->returnType;
    }

    // ----- helpers -----------------------------------------------------------------------------------------

    /** @param list<Node\Arg> $args @param \Closure(int): Type $eval */
    private function onReceiver(Node $n, Type $recv, string $name, array $args, \Closure $eval, Scope $scope, MethodRun $run): Type
    {
        if ($recv instanceof UnionT) {
            $results = [];
            foreach ($recv->members as $member) {
                $results[] = $this->onReceiver($n, $member, $name, $args, $eval, $scope, $run);
            }

            return TypeMerge::joinAll($results)->type;
        }
        if ($recv instanceof UnknownT) {
            $this->evaluateAll($args, $eval);

            return $recv;
        }

        $handled = $this->dispatch(new CallSite($n, $recv, null, $name, $args, $eval), $scope, $run);
        if ($handled !== null) {
            return $handled;
        }
        [$positional, $named] = $this->evaluateAll($args, $eval);
        if ($recv instanceof ObjectT || $recv instanceof ModelT) {
            return $this->callOnClass($recv->class, $name, $positional, $named, $n, $recv, $scope, $run);
        }

        return $this->gap($run, $scope, GapCode::VENDOR_CALL_NOT_MODELLED, $n, "{$name}() on a " . $this->kindName($recv) . ' is not modelled');
    }

    private function propertyOf(Node $n, Type $recv, string $name, Scope $scope, MethodRun $run): Type
    {
        if ($recv instanceof UnionT) {
            $results = [];
            foreach ($recv->members as $member) {
                $results[] = $this->propertyOf($n, $member, $name, $scope, $run);
            }

            return TypeMerge::joinAll($results)->type;
        }
        if ($recv instanceof UnknownT) {
            return $recv;
        }
        $handled = $this->dispatch(new CallSite($n, $recv, null, $name, [], null), $scope, $run);
        if ($handled !== null) {
            return $handled;
        }
        if ($recv instanceof PlainT) {
            // A value (a column, a scalar) has no properties the tool knows: reading one is not followed, never passed through (D21).
            return $this->gap($run, $scope, GapCode::UNRESOLVED_CALL, $n, "property {$name} is read on a value that is not an object: what it holds is not known");
        }
        if ($recv instanceof ObjectT) {
            $enum = $this->enumNode($recv->class);
            if ($enum !== null && $this->isEnumRead($enum, $name)) {
                // D45: the receiver is declared as an app enum; the name or backing value of a case touches nothing.
                return new PlainT();
            }
            if ($this->engine->source()->findClass($recv->class) === null) {
                return $this->gap($run, $scope, GapCode::VENDOR_CALL_NOT_MODELLED, $n, "property {$name} of vendor class {$recv->class} is not modelled");
            }
            $declared = $this->engine->types()->fromTypeString($this->engine->source()->propertyType($recv->class, $name), $recv->class);
            if ($declared !== null) {
                return $declared;
            }

            return $this->gap($run, $scope, GapCode::UNRESOLVED_CALL, $n, "the type of property {$recv->class}::\${$name} is not declared");
        }

        return $this->gap($run, $scope, GapCode::UNRESOLVED_CALL, $n, "property {$name} of a " . $this->kindName($recv) . ' is not modelled');
    }

    private function dispatch(CallSite $call, Scope $scope, MethodRun $run): ?Type
    {
        $ctx = new EvalContextImpl($this->engine, $run, $scope);
        foreach ($this->engine->registry()->handlers() as $handler) {
            if ($handler->handles($call, $ctx)) {
                return $handler->handle($call, $ctx);
            }
        }

        return null;
    }

    /**
     * @param array<Node\Arg|Node\VariadicPlaceholder> $raw
     * @return array{0: list<Node\Arg>, 1: \Closure(int): Type}
     */
    private function prepareArgs(array $raw, Scope $scope, MethodRun $run): array
    {
        $args = array_values(array_filter($raw, static fn (Node $a): bool => $a instanceof Node\Arg));
        $cache = [];
        $eval = function (int $i) use (&$cache, $args, $scope, $run): Type {
            return $cache[$i] ??= $this->walker->evaluateArg($args[$i]->value, $scope, $run);
        };

        return [$args, $eval];
    }

    /**
     * Evaluate every argument (once) and split them into positional and named.
     *
     * @param list<Node\Arg> $args
     * @param \Closure(int): Type $eval
     * @return array{0: list<Type>, 1: array<string, Type>}
     */
    private function evaluateAll(array $args, \Closure $eval): array
    {
        $positional = [];
        $named = [];
        foreach ($args as $i => $arg) {
            $type = $eval($i);
            if ($arg->name !== null) {
                $named[$arg->name->toString()] = $type;
            } else {
                $positional[] = $type;
            }
        }

        return [$positional, $named];
    }

    private function className(Node\Name $name, Scope $scope): ?string
    {
        return match (strtolower($name->toString())) {
            'self', 'static' => $scope->class,
            'parent' => $scope->declClass === null ? null : $this->engine->parentOf($scope->declClass),
            default => ltrim($name->toString(), '\\'),
        };
    }

    private function kindName(Type $t): string
    {
        return strtolower((new \ReflectionClass($t))->getShortName());
    }

    private function gap(MethodRun $run, Scope $scope, GapCode $code, Node $at, string $message): UnknownT
    {
        return (new EvalContextImpl($this->engine, $run, $scope))->gap($code, $at, $message);
    }
}
