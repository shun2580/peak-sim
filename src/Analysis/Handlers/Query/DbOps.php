<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Handlers\Query;

use PeakSim\Analysis\Contracts\CallSite;
use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Type\BuilderT;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ObjectT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Model\AssumptionCode;
use PeakSim\Model\Cardinality;
use PeakSim\Model\Delivery;
use PeakSim\Model\EventKind;
use PeakSim\Model\Origin;
use PeakSim\Model\QueryPart;
use PhpParser\Node;

/**
 * The DB facade. DB::table('orders') is a builder on a bare table; DB::select('raw sql') is a read of a size nobody
 * parsed, so it is taken as unbounded with no table (the worst side) and says so.
 */
final class DbOps
{
    public const FACADE = 'Illuminate\\Support\\Facades\\DB';

    private const METHODS = ['table', 'raw', 'select', 'selectone', 'scalar', 'statement', 'insert', 'update', 'delete', 'unprepared', 'transaction', 'connection'];

    public static function handlesName(string $name): bool
    {
        return in_array(strtolower($name), self::METHODS, true);
    }

    public function apply(CallSite $c, EvalContext $ctx): Type
    {
        $k = new QueryKit($ctx);

        return match (strtolower($c->name)) {
            'table' => $this->table($c, $k),
            'raw' => $this->raw($c, $k),
            'select' => $this->select($c, $k),
            'selectone', 'scalar' => $this->selectOne($c, $k),
            'transaction' => $this->transaction($c, $k),
            'connection' => $this->connection($c),
            default => $this->write($c, $k),
        };
    }

    private function table(CallSite $c, QueryKit $k): Type
    {
        $name = null;
        if (isset($c->args[0])) {
            $c->argType(0);
            $node = $c->argNode(0);
            $value = $node instanceof Node\Expr ? $k->ctx->source()->evaluate($node, null) : null;
            $name = $value !== null && $value->known && is_string($value->value) ? $value->value : null;
        }
        if ($name === null) {
            $k->ctx->assume(AssumptionCode::SQL_NOT_PARSED, $c->node, 'the table name is not a constant: how many rows it has is not known, the worst case is assumed');
        }

        return new BuilderT(null, $name);
    }

    private function raw(CallSite $c, QueryKit $k): Type
    {
        foreach (array_keys($c->args) as $i) {
            $c->argType($i);
        }
        $k->ctx->assume(AssumptionCode::SQL_NOT_PARSED, $c->node, 'raw SQL is not parsed');

        return new PlainT();
    }

    /** DB::select($sql): every row of a query nobody parsed. */
    private function select(CallSite $c, QueryKit $k): Type
    {
        foreach (array_keys($c->args) as $i) {
            $c->argType($i);
        }
        $k->ctx->assume(AssumptionCode::SQL_NOT_PARSED, $c->node, 'raw SQL is not parsed: it is taken to read an unbounded number of rows');
        $origin = new Origin(null);
        $k->events->statement(EventKind::Select, $c->node, null, new QueryPart(), QueryEvents::materialize($origin));

        return new ListT(new PlainT(), Cardinality::table(null), Delivery::Materialized);
    }

    private function selectOne(CallSite $c, QueryKit $k): Type
    {
        foreach (array_keys($c->args) as $i) {
            $c->argType($i);
        }
        $k->ctx->assume(AssumptionCode::SQL_NOT_PARSED, $c->node, 'raw SQL is not parsed');
        $k->events->statement(EventKind::Select, $c->node, null, new QueryPart());

        return new PlainT();
    }

    private function write(CallSite $c, QueryKit $k): Type
    {
        foreach (array_keys($c->args) as $i) {
            $c->argType($i);
        }
        $k->events->statement(EventKind::Write, $c->node, null, new QueryPart());

        return new PlainT();
    }

    private function transaction(CallSite $c, QueryKit $k): Type
    {
        $result = new PlainT();
        foreach (array_keys($c->args) as $i) {
            $type = $c->argType($i);
            if ($i === 0) {
                $closure = $k->callback($type, $c->node, 'DB::transaction()');
                if ($closure !== null) {
                    $result = $k->ctx->invokeClosure($closure, []);
                }
            }
        }

        return $result;
    }

    private function connection(CallSite $c): Type
    {
        return new ObjectT(self::FACADE);
    }
}
