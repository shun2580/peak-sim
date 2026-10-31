<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Known;

use PeakSim\Analysis\Contracts\CallHandler;
use PeakSim\Analysis\Contracts\CallSite;
use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Model\Cardinality;
use PeakSim\Model\CostEvent;
use PeakSim\Model\Delivery;
use PeakSim\Model\EventKind;
use PeakSim\Model\MemoryCause;
use PeakSim\Model\MemoryPart;
use PeakSim\Model\Origin;
use PeakSim\Model\QueryPart;
use PhpParser\Node\Expr;

/**
 * Four static reads on the two unit-app models, so a cached closure has something to cost: get() reads a whole
 * table, first() one row, count() a scalar, paginate() a bounded page. Not the real Query handlers.
 */
final class StubReads implements CallHandler
{
    private const TABLES = ['App\\Models\\Order' => 'orders', 'App\\Models\\Customer' => 'customers'];

    public function handles(CallSite $c, EvalContext $ctx): bool
    {
        return $c->node instanceof Expr\StaticCall
            && $c->staticClass !== null
            && isset(self::TABLES[$c->staticClass])
            && in_array($c->name, ['get', 'first', 'count', 'paginate'], true);
    }

    public function handle(CallSite $c, EvalContext $ctx): Type
    {
        $class = (string) $c->staticClass;
        $table = self::TABLES[$class];
        $site = $ctx->site($c->node);

        return match ($c->name) {
            'get' => $this->get($ctx, $site, $class, $table),
            'paginate' => $this->paginate($ctx, $site, $class, $table),
            'count' => $this->count($ctx, $site, $table),
            default => $this->first($ctx, $site, $class, $table),
        };
    }

    private function get(EvalContext $ctx, \PeakSim\Model\Site $site, string $class, string $table): Type
    {
        $ctx->emit(new CostEvent(
            EventKind::Select,
            $site,
            [],
            new QueryPart(),
            new MemoryPart(1, new Origin($table), MemoryCause::Materialize),
            $table,
            [],
            $ctx->seq(),
        ));

        return new ListT(new ModelT($class), Cardinality::table($table), Delivery::Materialized);
    }

    private function paginate(EvalContext $ctx, \PeakSim\Model\Site $site, string $class, string $table): Type
    {
        $ctx->emit(new CostEvent(EventKind::Select, $site, [], new QueryPart(statements: 2), null, $table, [], $ctx->seq()));

        return new ListT(new ModelT($class), Cardinality::bounded(), Delivery::Materialized);
    }

    private function count(EvalContext $ctx, \PeakSim\Model\Site $site, string $table): Type
    {
        $ctx->emit(new CostEvent(EventKind::Aggregate, $site, [], new QueryPart(), null, $table, [], $ctx->seq()));

        return new PlainT();
    }

    private function first(EvalContext $ctx, \PeakSim\Model\Site $site, string $class, string $table): Type
    {
        $ctx->emit(new CostEvent(EventKind::Select, $site, [], new QueryPart(), null, $table, [], $ctx->seq()));

        return new ModelT($class);
    }
}
