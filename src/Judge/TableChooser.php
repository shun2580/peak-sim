<?php

declare(strict_types=1);

namespace PeakSim\Judge;

use PeakSim\Model\Axis;
use PeakSim\Model\CostEvent;

/**
 * Names N and M (design 1.8): the event with the largest degree on either axis; on a tie the query
 * axis, then the kind (the select of the rows before an eager or lazy load), then the smaller seq.
 */
final class TableChooser
{
    /** @param list<CostEvent> $events in seq order */
    public static function choose(array $events): TableChoice
    {
        $ranked = [];
        foreach ($events as $e) {
            foreach ([Axis::Query, Axis::Memory] as $axisIndex => $axis) {
                $layers = Degree::layers($axis, $e);
                if ($layers !== null) {
                    $ranked[] = [count($layers), $axisIndex, AxisJudge::rank($e->kind), $e->seq, $e, $axis, $layers];
                }
            }
        }
        if ($ranked === []) {
            return new TableChoice(null, null, null, []);
        }
        usort($ranked, static fn (array $a, array $b): int => [$b[0], $a[1], $a[2], $a[3], $a[4]->fingerprint()] <=> [$a[0], $b[1], $b[2], $b[3], $b[4]->fingerprint()]);

        [$degree, $axisIndex, $kind, , $event, $axis, $layers] = $ranked[0];
        $n = $layers === [] ? Degree::ownTable($axis, $event) : $layers[0]->table;
        $alternatives = [];
        foreach (array_slice($ranked, 1) as [$d, $a, $k, , $e, $ax, $l]) {
            if ($d !== $degree || $a !== $axisIndex || $k !== $kind) {
                continue;
            }
            $other = $l === [] ? Degree::ownTable($ax, $e) : $l[0]->table;
            if ($other !== null && $other !== $n && !in_array($other, $alternatives, true)) {
                $alternatives[] = $other;
            }
        }

        return new TableChoice($n, $layers[1]->table ?? null, $layers[1]->perTable ?? null, $alternatives);
    }
}
