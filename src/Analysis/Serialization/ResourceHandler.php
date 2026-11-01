<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Serialization;

use PeakSim\Analysis\Contracts\CallHandler;
use PeakSim\Analysis\Contracts\CallSite;
use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Engine\TypeMerge;
use PeakSim\Analysis\Engine\TypeResolver;
use PeakSim\Analysis\Handlers\Query\RelationOps;
use PeakSim\Analysis\Type\ClosureT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\ResourceT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Analysis\Type\UnionT;
use PeakSim\Analysis\Type\UnknownT;
use PeakSim\Model\GapCode;
use PhpParser\Node;
use PhpParser\Node\Expr;

/**
 * JsonResource and ResourceCollection.
 *
 *  - `XxxResource::collection(x)`, `XxxResource::make(x)` and `new XxxResource(x)` make a ResourceT. `collection()`
 *    is defined by the vendor class, so without this handler the call would be an unresolved vendor call.
 *  - A ResourceT is also `$this` inside toArray(): the wrapped model seen through the resource. `$this->customer`
 *    is the model's property (a lazy load, as for the model itself); `$this->whenLoaded('customer')` never loads;
 *    `$this->when(...)` and `mergeWhen(...)` run their closure once; the resource's own methods are followed, and
 *    calls it does not declare go on to the model, as Laravel forwards them.
 *  - `parent::toArray($request)` is the serialisation of the wrapped model (or of the elements, for a collection).
 *
 * The calls that fire the sink (`resolve()`, `toArray()`, `response()`...) belong to the ResponseSink.
 */
final class ResourceHandler implements CallHandler
{
    /** Chaining methods that return the same resource and read nothing. */
    private const PASS_THROUGH = ['additional', 'preservekeys', 'withoutwrapping', 'wrap', 'withresponse'];

    /**
     * Conditional attribute helpers: name => [index of the value argument, index of the default argument, closure arguments].
     * The value and default may be closures; they are run once, because PHP evaluates them when the condition holds.
     *
     * @var array<string, array{0: int, 1: int|null, 2: int}>
     */
    private const CONDITIONALS = [
        'when' => [1, 2, 0],
        'unless' => [1, 2, 0],
        'mergewhen' => [1, null, 0],
        'mergeunless' => [1, null, 0],
        'merge' => [0, null, 0],
        'whencounted' => [1, 2, 1],
        'whenhas' => [1, 2, 1],
        'whennotnull' => [0, 1, 0],
        'whenpivotloaded' => [1, 2, 0],
        'whenpivotloadedas' => [2, 3, 0],
        'whenaggregated' => [3, 4, 1],
    ];

    public function __construct(private readonly Serializer $serializer)
    {
    }

    public function handles(CallSite $c, EvalContext $ctx): bool
    {
        if ($c->receiver instanceof ResourceT) {
            return !($c->node instanceof Expr\MethodCall || $c->node instanceof Expr\NullsafeMethodCall) || !in_array(strtolower($c->name), ResponseSink::RESOURCE_METHODS, true);
        }
        if ($c->receiver !== null || $c->staticClass === null) {
            return false;
        }
        $class = ltrim($c->staticClass, '\\');
        if ($c->node instanceof Expr\New_) {
            return ResourceClasses::isResource($class, $ctx);
        }
        if (!$c->node instanceof Expr\StaticCall) {
            return false;
        }
        $name = strtolower($c->name);
        if (!ResourceClasses::isResource($class, $ctx)) {
            return false;
        }
        $declared = $ctx->source()->findClass($class) !== null && $ctx->source()->resolveMethod($class, $c->name)->method !== null;

        return match ($name) {
            'collection', 'make' => !$declared,
            'toarray' => !ResourceClasses::declaresToArray($class, $ctx),
            default => false,
        };
    }

    public function handle(CallSite $c, EvalContext $ctx): Type
    {
        if ($c->receiver instanceof ResourceT) {
            return $c->node instanceof Expr\PropertyFetch || $c->node instanceof Expr\NullsafePropertyFetch
                ? $this->property($c->receiver, $c, $ctx)
                : $this->method($c->receiver, $c, $ctx);
        }
        $class = ltrim((string) $c->staticClass, '\\');
        if ($c->node instanceof Expr\StaticCall && strtolower($c->name) === 'toarray') {
            return $this->parentToArray($c, $ctx);
        }
        $inner = isset($c->args[0]) ? $c->argType(0) : new PlainT();
        foreach (array_keys($c->args) as $i) {
            $c->argType($i);
        }
        $collection = ResourceClasses::isCollection($class, $ctx);
        $many = $c->node instanceof Expr\StaticCall ? strtolower($c->name) === 'collection' || $collection : $collection;

        return new ResourceT($class, $inner, $many);
    }

    // ---- parent::toArray() ---------------------------------------------------------------------------------

    private function parentToArray(CallSite $c, EvalContext $ctx): Type
    {
        foreach (array_keys($c->args) as $i) {
            $c->argType($i);
        }
        $self = $ctx->evaluate(new Expr\Variable('this'));
        if (!$self instanceof ResourceT) {
            return $ctx->gap(GapCode::RESOURCE_NOT_ANALYZED, $c->node, 'parent::toArray() outside a resource: what it returns is not known');
        }
        if ($self->many) {
            $element = ResourceClasses::elementOf($self->resourceClass, $ctx);
            if ($element === null) {
                return $ctx->gap(GapCode::RESOURCE_NOT_ANALYZED, $c->node, "{$self->resourceClass} names no element resource: what its elements run is not known");
            }
            $this->serializer->serialize(new ResourceT($element, $self->inner, true), $c->node, $ctx);
        } else {
            $this->serializer->serialize($self->inner, $c->node, $ctx);
        }

        return new PlainT();
    }

    // ---- $this->x ------------------------------------------------------------------------------------------

    private function property(ResourceT $r, CallSite $c, EvalContext $ctx): Type
    {
        if (strtolower($c->name) === 'resource') {
            return $r->inner;
        }
        if ($r->many) {
            if (strtolower($c->name) === 'collection') {
                $element = ResourceClasses::elementOf($r->resourceClass, $ctx);

                return $element === null
                    ? $ctx->gap(GapCode::RESOURCE_NOT_ANALYZED, $c->node, "{$r->resourceClass} names no element resource: \$this->collection is not known")
                    : new ResourceT($element, $r->inner, true);
            }

            return $ctx->gap(GapCode::RESOURCE_NOT_ANALYZED, $c->node, "property {$c->name} of the resource collection {$r->resourceClass} is not modelled");
        }

        $declared = $this->declared($r, $c, $ctx);
        if ($declared !== null) {
            return $declared;
        }

        return $this->onWrapped($r->inner, $c, $ctx);
    }

    /**
     * `$this->order` where the resource class declares (or promotes) a property `order` of a class type: PHP reads that
     * property, it does not forward to the wrapped model, so the value is what the declaration says (a model, an object).
     * A property with no class type (untyped, scalar, union) says nothing here and is left to the forwarding path.
     */
    private function declared(ResourceT $r, CallSite $c, EvalContext $ctx): ?Type
    {
        $text = $ctx->source()->propertyType($r->resourceClass, $c->name);

        return $text === null ? null : (new TypeResolver($ctx->models()))->fromTypeString($text, $r->resourceClass);
    }

    /** A property read or a call that a resource forwards to the model it wraps. */
    private function onWrapped(Type $inner, CallSite $c, EvalContext $ctx): Type
    {
        if ($inner instanceof UnionT) {
            return TypeMerge::joinAll(array_map(fn (Type $member): Type => $this->onWrapped($member, $c, $ctx), $inner->members))->type;
        }
        if ($inner instanceof UnknownT) {
            foreach (array_keys($c->args) as $i) {
                $c->argType($i);
            }

            return $inner;
        }
        if ($inner instanceof PlainT) {
            foreach (array_keys($c->args) as $i) {
                $c->argType($i);
            }

            return $inner;
        }
        if (!$inner instanceof ModelT) {
            return $ctx->gap(GapCode::RESOURCE_NOT_ANALYZED, $c->node, "{$c->name} on a resource that wraps something other than a model is not modelled");
        }
        $forwarded = new CallSite($c->node, $inner, null, $c->name, $c->args, fn (int $i): Type => $c->argType($i));
        // The model's relations are told by name (their methods only describe them); then the model's own methods.
        $relations = new RelationOps();
        if ($relations->handles($forwarded, $ctx)) {
            return $relations->handle($forwarded, $ctx);
        }
        $isCall = $c->node instanceof Expr\MethodCall || $c->node instanceof Expr\NullsafeMethodCall;
        if ($isCall && $ctx->source()->resolveMethod($inner->class, $c->name)->method !== null) {
            return $this->callOwn($inner->class, $inner, $c, $ctx);
        }
        foreach (array_keys($c->args) as $i) {
            $c->argType($i);
        }

        return $ctx->gap(GapCode::RESOURCE_NOT_ANALYZED, $c->node, "{$c->name}() is neither declared by the resource nor by {$inner->class}: what it does is not known");
    }

    // ---- $this->method() -----------------------------------------------------------------------------------

    private function method(ResourceT $r, CallSite $c, EvalContext $ctx): Type
    {
        $name = strtolower($c->name);
        if (in_array($name, self::PASS_THROUGH, true)) {
            foreach (array_keys($c->args) as $i) {
                $c->argType($i);
            }

            return $r;
        }
        if ($name === 'whenloaded') {
            return $this->whenLoaded($r, $c, $ctx);
        }
        if (isset(self::CONDITIONALS[$name])) {
            return $this->conditional($c, $ctx, ...self::CONDITIONALS[$name]);
        }
        if ($ctx->source()->findClass($r->resourceClass) !== null && $ctx->source()->resolveMethod($r->resourceClass, $c->name)->method?->node->stmts !== null) {
            return $this->callOwn($r->resourceClass, $r, $c, $ctx);
        }
        if ($r->many) {
            foreach (array_keys($c->args) as $i) {
                $c->argType($i);
            }

            return $ctx->gap(GapCode::RESOURCE_NOT_ANALYZED, $c->node, "{$c->name}() on the resource collection {$r->resourceClass} is not modelled");
        }

        return $this->onWrapped($r->inner, $c, $ctx);
    }

    /** A method the app declares (on the resource or on the wrapped model), run with $self as `$this`. */
    private function callOwn(string $class, Type $self, CallSite $c, EvalContext $ctx): Type
    {
        $types = [];
        foreach ($c->args as $i => $arg) {
            $type = $c->argType($i);
            if ($arg->name !== null) {
                return $ctx->gap(GapCode::RESOURCE_NOT_ANALYZED, $c->node, "{$c->name}() is called with named arguments: not followed");
            }
            $types[] = $type;
        }

        return $ctx->callMethod($class, $c->name, $types, $c->node, $self);
    }

    private function whenLoaded(ResourceT $r, CallSite $c, EvalContext $ctx): Type
    {
        $named = isset($c->args[0]) ? $ctx->source()->evaluate($c->args[0]->value, null) : null;
        $c->argType(0);
        if ($named === null || !$named->known || !is_string($named->value)) {
            $this->evaluateRest($c, [], $ctx);

            return $ctx->gap(GapCode::RESOURCE_NOT_ANALYZED, $c->node, 'whenLoaded() with a relation name that is not a constant: whether it is loaded is not known');
        }
        $inner = $r->inner;
        $relation = $inner instanceof ModelT && in_array($named->value, $inner->eager, true)
            ? RelationValues::loaded($inner, $named->value, $c->node, $ctx)
            : null;
        if ($relation === null) {
            // Not loaded (or not a model): nothing is read, only the default is evaluated.
            $default = $this->valueOf($c, 2, [], $ctx);
            $this->valueOf($c, 1, [], $ctx, false);

            return $default ?? new PlainT();
        }
        $value = $this->valueOf($c, 1, [$relation], $ctx);
        $this->valueOf($c, 2, [], $ctx, false);

        return $value ?? $relation;
    }

    private function conditional(CallSite $c, EvalContext $ctx, int $valueAt, ?int $defaultAt, int $closureArgs): Type
    {
        $given = array_fill(0, $closureArgs, new PlainT());
        $this->evaluateRest($c, [$valueAt, $defaultAt], $ctx);
        $value = $this->valueOf($c, $valueAt, $given, $ctx);
        $default = $defaultAt === null ? null : $this->valueOf($c, $defaultAt, $given, $ctx);
        if ($value !== null && $default !== null) {
            return TypeMerge::joinAll([$value, $default])->type;
        }

        return $value ?? $default ?? new PlainT();
    }

    /**
     * The type of argument $i; a closure is run once with $closureArgs unless $run is false (evaluated, not called).
     *
     * @param list<Type> $closureArgs
     */
    private function valueOf(CallSite $c, int $i, array $closureArgs, EvalContext $ctx, bool $run = true): ?Type
    {
        if (!isset($c->args[$i])) {
            return null;
        }
        $type = $c->argType($i);

        return $type instanceof ClosureT && $run ? $ctx->invokeClosure($type, $closureArgs) : $type;
    }

    /**
     * Evaluate the arguments that are neither the value nor the default (the condition, the relation name...).
     *
     * @param list<int|null> $except
     */
    private function evaluateRest(CallSite $c, array $except, EvalContext $ctx): void
    {
        foreach (array_keys($c->args) as $i) {
            if (!in_array($i, $except, true)) {
                $c->argType($i);
            }
        }
    }
}
