<?php

declare(strict_types=1);

namespace PeakSim\Judge;

use PeakSim\Model\Axis;
use PeakSim\Model\CostEvent;

/** The shown-only numbers beside a verdict. Each is null when it cannot be derived; none is ever compared. */
final class Coefficients
{
    /**
     * @param list<CostEvent> $events in seq order
     * @return array{query_coefficient: ?string, per_item_queries: ?int, extra_queries_per_request: ?int}
     */
    public static function of(array $events): array
    {
        $chunk = null;
        $perItem = null;
        $constantStatements = 0;
        foreach ($events as $e) {
            $degree = Degree::of(Axis::Query, $e);
            if ($degree === null || $e->query === null) {
                continue;
            }
            if ($e->query->ownUnbounded && $e->query->chunkSize !== null && $chunk === null) {
                $chunk = '1/' . $e->query->chunkSize;
            }
            if ($e->query->conditional && $degree >= 1) {
                $perItem = max($perItem ?? 0, $e->query->statements);
            }
            if ($degree === 0) {
                $constantStatements += $e->query->statements;
            }
        }

        return [
            'query_coefficient' => $chunk,
            'per_item_queries' => $perItem,
            'extra_queries_per_request' => $constantStatements >= 2 ? $constantStatements - 1 : null,
        ];
    }
}
