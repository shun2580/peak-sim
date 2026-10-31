<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Engine\Support;

use PeakSim\Analysis\Contracts\CallHandler;
use PeakSim\Analysis\Contracts\CallSite;
use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Type\BuilderT;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Model\Cardinality;
use PeakSim\Model\CostEvent;
use PeakSim\Model\Delivery;
use PeakSim\Model\EventKind;
use PeakSim\Model\GapCode;
use PeakSim\Model\MemoryCause;
use PeakSim\Model\MemoryPart;
use PeakSim\Model\Origin;
use PeakSim\Model\QueryPart;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;

/**
 * Just enough of the query handler (which another person writes) to walk the engine's test apps: Order::get(),
 * with(), first(), and a to-one / to-many relation read. It is NOT the real handler and is not complete.
 */
final class StubQueryHandler implements CallHandler
{
    private const PASSTHROUGH = ['where', 'orderBy', 'latest', 'oldest', 'whereIn'];

    public function handles(CallSite $c, EvalContext $ctx): bool
    {
        return ($c->node instanceof Expr\StaticCall && $c->staticClass !== null && $ctx->models()->isModel($c->staticClass))
            || ($c->node instanceof Expr\MethodCall && $c->receiver instanceof BuilderT)
            || ($c->node instanceof Expr\PropertyFetch && $c->receiver instanceof ModelT);
    }

    public function handle(CallSite $c, EvalContext $ctx): Type
    {
        if ($c->node instanceof Expr\PropertyFetch) {
            return $this->property($c, $ctx);
        }
        $builder = $c->receiver instanceof BuilderT ? $c->receiver : new BuilderT((string) $c->staticClass);
        $class = (string) $builder->class;
        $table = $ctx->models()->describe($class)?->table ?? '?';
        $site = $ctx->site($c->node);

        if ($c->name === 'with') {
            $names = [];
            for ($i = 0; $c->argNode($i) !== null; $i++) {
                $arg = $c->argNode($i);
                if ($arg instanceof Scalar\String_) {
                    $names[] = $arg->value;
                    $ctx->emit(new CostEvent(EventKind::EagerLoad, $site, [], new QueryPart(), null, $table, [], $ctx->seq()));
                }
            }

            return $builder->withEager($names);
        }
        if (in_array($c->name, self::PASSTHROUGH, true)) {
            return $builder;
        }
        if ($c->name === 'get' || $c->name === 'all') {
            $ctx->emit(new CostEvent(EventKind::Select, $site, [], new QueryPart(), new MemoryPart(1, new Origin($table), MemoryCause::Materialize), $table, [], $ctx->seq()));

            return new ListT(new ModelT($class, $builder->eager), Cardinality::table($table), Delivery::Materialized);
        }
        if ($c->name === 'first' || $c->name === 'find') {
            $ctx->emit(new CostEvent(EventKind::Select, $site, [], new QueryPart(), null, $table, [], $ctx->seq()));

            return new ModelT($class, $builder->eager);
        }

        return $ctx->gap(GapCode::UNKNOWN_BUILDER_METHOD, $c->node, "stub: {$c->name}() is not a stubbed builder method");
    }

    private function property(CallSite $c, EvalContext $ctx): Type
    {
        $model = $c->receiver;
        assert($model instanceof ModelT);
        $relation = $ctx->models()->describe($model->class)?->relations[$c->name] ?? null;
        if ($relation === null) {
            return new PlainT();
        }
        $related = (string) $relation->related;
        $nested = [];
        foreach ($model->eager as $loaded) {
            if (str_starts_with($loaded, $c->name . '.')) {
                $nested[] = substr($loaded, strlen($c->name) + 1);
            }
        }
        $relatedTable = $ctx->models()->describe($related)?->table ?? '?';
        $parentTable = $ctx->models()->describe($model->class)?->table;
        if (!in_array($c->name, $model->eager, true)) {
            $ctx->emit(new CostEvent(
                EventKind::LazyLoad,
                $ctx->site($c->node),
                [],
                new QueryPart(),
                $relation->many ? new MemoryPart(1, new Origin($relatedTable, $parentTable), MemoryCause::LazyToMany) : null,
                $relatedTable,
                [],
                $ctx->seq(),
            ));
        }

        return $relation->many
            ? new ListT(new ModelT($related, $nested), Cardinality::table($relatedTable, $parentTable), Delivery::Materialized)
            : new ModelT($related, $nested);
    }
}
