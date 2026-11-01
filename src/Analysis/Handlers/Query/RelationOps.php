<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Handlers\Query;

use PeakSim\Analysis\Contracts\CallHandler;
use PeakSim\Analysis\Contracts\CallSite;
use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Type\BuilderT;
use PeakSim\Analysis\Type\ClosureT;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Analysis\Type\UnknownT;
use PeakSim\Model\Cardinality;
use PeakSim\Model\Delivery;
use PeakSim\Model\EventKind;
use PeakSim\Model\GapCode;
use PeakSim\Model\MemoryCause;
use PeakSim\Model\MemoryPart;
use PeakSim\Model\Origin;
use PeakSim\Model\QueryPart;
use PeakSim\Source\ModelDescriptor;
use PhpParser\Node;

/**
 * Calls on a model row: reading an attribute, a relation as an attribute (`$order->items`, a lazy load unless it was
 * loaded) or as a builder (`$order->items()`), loading relations later (`load`, `loadMissing`), refreshing, and the
 * writes that act on the row (`save`, `update`, `delete`...).
 *
 * `$order->items` and `$order->items()` are told apart by the node: a PropertyFetch is the attribute, a MethodCall the builder.
 */
final class RelationOps implements CallHandler
{
    private const WRITES = ['save', 'update', 'delete', 'forcedelete', 'restore', 'touch', 'push', 'increment', 'decrement', 'savequietly', 'updatequietly', 'deletequietly'];

    private const LOADS = ['load', 'loadmissing'];

    private const LOAD_AGGREGATES = ['loadcount', 'loadsum', 'loadavg', 'loadmin', 'loadmax', 'loadexists'];

    private const REFRESHES = ['refresh', 'fresh'];

    /**
     * `$order->newQuery()` is Eloquent's `Model::newQuery()`: a fresh Eloquent builder for the row's model, as
     * `Order::query()` is. It is the only builder-making method known on a row (D28); any other name the model
     * does not define stays a gap.
     */
    private const NEW_QUERY = ['newquery'];

    public function __construct(private readonly BuilderOps $ops = new BuilderOps())
    {
    }

    public function handles(CallSite $c, EvalContext $ctx): bool
    {
        if (!$c->receiver instanceof ModelT) {
            return false;
        }
        if ($this->isAttributeFetch($c->node)) {
            return true;
        }
        $descriptor = $this->peek($c->receiver->class, $ctx);
        if ($descriptor !== null && isset($descriptor->relations[$c->name])) {
            return true;
        }
        $key = strtolower($c->name);
        if (!in_array($key, [...self::WRITES, ...self::LOADS, ...self::LOAD_AGGREGATES, ...self::REFRESHES, ...self::NEW_QUERY], true)) {
            return false;
        }
        try {
            // A method the model defines itself is the app's code, not Eloquent's.
            return !$ctx->source()->resolveMethod($c->receiver->class, $c->name)->isFound();
        } catch (\Throwable) {
            return false;
        }
    }

    public function handle(CallSite $c, EvalContext $ctx): Type
    {
        $row = $c->receiver;
        \assert($row instanceof ModelT);
        $k = new QueryKit($ctx);
        $descriptor = $k->facts->describe($row->class, $c->node);
        if ($descriptor instanceof UnknownT) {
            return $descriptor;
        }
        if ($this->isAttributeFetch($c->node)) {
            return $this->attribute($row, $descriptor, $c, $k);
        }
        if (isset($descriptor->relations[$c->name])) {
            return $this->relationBuilder($row, $descriptor, $c, $k);
        }
        $key = strtolower($c->name);

        return match (true) {
            in_array($key, self::LOADS, true) => $this->load($row, $c, $k, $key === 'loadmissing'),
            in_array($key, self::LOAD_AGGREGATES, true) => $this->loadAggregate($row, $descriptor, $c, $k),
            in_array($key, self::REFRESHES, true) => $this->refresh($row, $c, $k, $key === 'fresh'),
            in_array($key, self::NEW_QUERY, true) => $this->ops->newBuilder($row->class, $c->node, $k),
            default => $this->ops->apply(new BuilderT($row->class, $k->facts->table($descriptor, $c->node), $row->eager), $c, $ctx, true),
        };
    }

    private function isAttributeFetch(Node $node): bool
    {
        return $node instanceof Node\Expr\PropertyFetch || $node instanceof Node\Expr\NullsafePropertyFetch;
    }

    private function peek(string $class, EvalContext $ctx): ?ModelDescriptor
    {
        return (new ModelFacts($ctx))->peek($class);
    }

    // ---- attributes ----------------------------------------------------------------------------------------

    private function attribute(ModelT $row, ModelDescriptor $d, CallSite $c, QueryKit $k): Type
    {
        $relation = $k->facts->relation($d, $c->name, $c->node);
        if ($relation !== null) {
            return $this->lazyLoad($row, $d, $c, $k, $relation->related, $relation->many);
        }
        $accessor = $d->accessors[$c->name] ?? $d->accessors[$this->snake($c->name)] ?? null;
        if ($accessor === null) {
            return new PlainT();
        }
        $result = $k->ctx->callMethod($row->class, $accessor, [], $c->node, $row);

        return $result instanceof ClosureT ? $k->ctx->invokeClosure($result, [new PlainT(), new PlainT()]) : $result;
    }

    private function snake(string $name): string
    {
        return strtolower((string) preg_replace('/(.)(?=[A-Z])/u', '$1_', $name));
    }

    /** $order->items / $order->customer: no query when it was loaded with the row, otherwise one per access. */
    private function lazyLoad(ModelT $row, ModelDescriptor $d, CallSite $c, QueryKit $k, ?string $related, bool $many): Type
    {
        if ($related === null) {
            return $k->ctx->gap(GapCode::RELATION_TARGET_UNKNOWN, $c->node, "the related model of {$row->class}::{$c->name}() is not known");
        }
        $relatedDescriptor = $k->facts->describe($related, $c->node);
        if ($relatedDescriptor instanceof UnknownT) {
            return $relatedDescriptor;
        }
        $table = $k->facts->table($relatedDescriptor, $c->node);
        $owner = $k->facts->table($d, $c->node);
        if (in_array($c->name, $row->eager, true)) {
            $nested = $k->eager->nested($row->eager, $c->name);
        } else {
            $origin = new Origin($table, $owner);
            $k->events->statement(EventKind::LazyLoad, $c->node, $table, new QueryPart(), $many ? new MemoryPart(1, $origin, MemoryCause::LazyToMany) : null);
            $nested = $k->eager->seed($related, $c->node);
            $k->eager->emit($related, $nested, $c->node, $many ? EagerLoads::MANY : EagerLoads::SINGLE);
        }

        return $many
            ? new ListT(new ModelT($related, $nested, new Origin($table)), Cardinality::table($table, $owner), Delivery::Materialized)
            : new ModelT($related, $nested);
    }

    // ---- relation builders and loads -----------------------------------------------------------------------

    /** $order->items(): the relation as a query, not run yet. A to-one relation is at most one row, so it is bounded. */
    private function relationBuilder(ModelT $row, ModelDescriptor $d, CallSite $c, QueryKit $k): Type
    {
        foreach (array_keys($c->args) as $i) {
            $c->argType($i);
        }
        $relation = $k->facts->relation($d, $c->name, $c->node);
        if ($relation === null || $relation->related === null) {
            return $k->ctx->gap(GapCode::RELATION_TARGET_UNKNOWN, $c->node, "the related model of {$row->class}::{$c->name}() is not known");
        }
        $builder = $this->ops->newBuilder($relation->related, $c->node, $k);
        if (!$builder instanceof BuilderT) {
            return $builder;
        }

        return new BuilderT($builder->class, $builder->table, $builder->eager, !$relation->many, [$row->class, $c->name]);
    }

    private function load(ModelT $row, CallSite $c, QueryKit $k, bool $missing): Type
    {
        $paths = [];
        foreach ($c->args as $i => $arg) {
            $c->argType($i);
            $node = $arg->value;
            $items = $node instanceof Node\Expr\Array_ ? array_map(static fn ($item) => $item->key ?? $item->value, $node->items) : [$node];
            foreach ($items as $item) {
                $value = $item instanceof Node\Expr ? $k->ctx->source()->evaluate($item, null) : null;
                if ($value !== null && $value->known && is_string($value->value)) {
                    $colon = strpos($value->value, ':');
                    $paths[] = $colon === false ? $value->value : substr($value->value, 0, $colon);
                } else {
                    $k->ctx->gap(GapCode::RELATION_TARGET_UNKNOWN, $c->node, 'a relation name given to ' . $c->name . '() is not a constant: the relations it loads are not known');
                }
            }
        }
        $names = $k->eager->expand($row->class, $paths, $c->node);
        $toLoad = $missing ? array_values(array_diff($names, $row->eager)) : $names;
        $k->eager->emit($row->class, $toLoad, $c->node, EagerLoads::SINGLE);

        return $row->withEager($names);
    }

    /** loadCount('items') and friends: one aggregate query for the row. */
    private function loadAggregate(ModelT $row, ModelDescriptor $d, CallSite $c, QueryKit $k): Type
    {
        foreach (array_keys($c->args) as $i) {
            $c->argType($i);
        }
        $k->events->statement(EventKind::Aggregate, $c->node, $d->table, new QueryPart());

        return $row;
    }

    private function refresh(ModelT $row, CallSite $c, QueryKit $k, bool $fresh): Type
    {
        foreach (array_keys($c->args) as $i) {
            $c->argType($i);
        }
        $descriptor = $k->facts->peek($row->class);
        $k->events->statement(EventKind::Select, $c->node, $descriptor?->table, new QueryPart());

        return $row;
    }
}
