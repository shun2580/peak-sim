<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Serialization;

use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Handlers\Query\QueryKit;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Analysis\Type\UnknownT;
use PeakSim\Model\Cardinality;
use PeakSim\Model\Delivery;
use PeakSim\Model\Origin;
use PhpParser\Node;

/**
 * The value of a relation a row already carries (it was loaded with the row): the related row, or the list of them
 * as it would be after a lazy load, but without the query. Reading it costs nothing; what it holds is serialised
 * by the caller.
 */
final class RelationValues
{
    /** @return Type|null null when the model has no such relation or its related model is not known */
    public static function loaded(ModelT $row, string $name, Node $at, EvalContext $ctx): ?Type
    {
        $kit = new QueryKit($ctx);
        $descriptor = $kit->facts->describe($row->class, $at);
        if ($descriptor instanceof UnknownT) {
            return null;
        }
        $relation = $kit->facts->relation($descriptor, $name, $at);
        if ($relation === null || $relation->related === null) {
            return null;
        }
        $table = $kit->facts->peek($relation->related)?->table;
        $nested = $kit->eager->nested($row->eager, $name);
        if (!$relation->many) {
            return new ModelT($relation->related, $nested);
        }

        return new ListT(new ModelT($relation->related, $nested, new Origin($table)), Cardinality::table($table, $descriptor->table), Delivery::Materialized);
    }
}
