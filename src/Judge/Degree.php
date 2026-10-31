<?php

declare(strict_types=1);

namespace PeakSim\Judge;

use PeakSim\Model\Axis;
use PeakSim\Model\CostEvent;
use PeakSim\Model\Frame;
use PeakSim\Model\Origin;

/**
 * The one place that turns an event into a degree (design 1.5).
 *
 * query:  |frames| + (ownUnbounded ? 1 : 0)
 * memory: |frames that are not streamed| + ownDegree, or |frames[retainFrom..]| + ownDegree when the event retains
 */
final class Degree
{
    /** The degree of the event on the axis, or null when the event has no part on that axis. */
    public static function of(Axis $axis, CostEvent $e): ?int
    {
        $layers = self::layers($axis, $e);

        return $layers === null ? null : count($layers);
    }

    /**
     * Where each level of the degree comes from, outermost first. The first is N, the second M.
     *
     * @return list<Origin>|null null when the event has no part on that axis
     */
    public static function layers(Axis $axis, CostEvent $e): ?array
    {
        if ($axis === Axis::Query) {
            if ($e->query === null) {
                return null;
            }
            $layers = array_map(static fn (Frame $f): Origin => $f->origin, $e->frames);
            if ($e->query->ownUnbounded) {
                $layers[] = $e->query->ownOrigin ?? new Origin($e->table);
            }

            return $layers;
        }
        if ($e->memory === null) {
            return null;
        }
        $counted = $e->memory->retainFrom !== null
            ? array_slice($e->frames, max(0, $e->memory->retainFrom))
            : array_filter($e->frames, static fn (Frame $f): bool => !$f->streamed);
        $layers = array_map(static fn (Frame $f): Origin => $f->origin, array_values($counted));
        for ($i = 0; $i < $e->memory->ownDegree; $i++) {
            $layers[] = $e->memory->ownOrigin ?? new Origin($e->table);
        }

        return $layers;
    }

    /** The table an event with no growing level is about: its own origin, else the table it reads. */
    public static function ownTable(Axis $axis, CostEvent $e): ?string
    {
        $own = $axis === Axis::Query ? $e->query?->ownOrigin : $e->memory?->ownOrigin;

        return $own?->table ?? $e->table;
    }
}
