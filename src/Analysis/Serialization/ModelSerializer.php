<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Serialization;

use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Handlers\Query\QueryKit;
use PeakSim\Analysis\Type\ClosureT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\UnknownT;
use PeakSim\Model\GapCode;
use PhpParser\Node;

/**
 * One model row turned into an array: every name in $appends runs its accessor with the row as $this (whether or
 * not the result is ever used), and every relation the row carries is serialised in turn. $hidden and $visible are
 * not looked at: hiding an attribute does not stop the accessor from running.
 */
final class ModelSerializer
{
    public function __construct(private readonly Serializer $serializer)
    {
    }

    public function serialize(ModelT $row, Node $at, EvalContext $ctx): void
    {
        $key = 'model:' . $row->class . '[' . implode(',', $row->eager) . ']';
        $this->serializer->guarded($key, $row->class, $at, $ctx, function () use ($row, $at, $ctx): void {
            $kit = new QueryKit($ctx);
            $descriptor = $kit->facts->describe($row->class, $at);
            if ($descriptor instanceof UnknownT) {
                return;
            }
            if ($descriptor->unresolved !== []) {
                $ctx->gap(GapCode::MODEL_PROPERTY_UNRESOLVED, $at, "{$row->class} has " . implode(' and ', array_map(static fn (string $p): string => '$' . $p, $descriptor->unresolved)) . ' that could not be read: what serialising it runs is not known');
            }
            foreach ($descriptor->appends as $name) {
                $accessor = $descriptor->accessors[$name] ?? null;
                if ($accessor === null) {
                    $ctx->gap(GapCode::ACCESSOR_NOT_ANALYZED, $at, "{$row->class} appends '{$name}' but no accessor for it was found in the app");

                    continue;
                }
                $result = $ctx->callMethod($row->class, $accessor, [], $at, $row);
                if ($result instanceof ClosureT) {
                    $result = $ctx->invokeClosure($result, [new PlainT(), new PlainT()]);
                }
                $this->serializer->serialize($result, $at, $ctx);
            }
            foreach ($row->eager as $name) {
                if (str_contains($name, '.')) {
                    continue;
                }
                $loaded = RelationValues::loaded($row, $name, $at, $ctx);
                if ($loaded !== null) {
                    $this->serializer->serialize($loaded, $at, $ctx);
                }
            }
        });
    }
}
