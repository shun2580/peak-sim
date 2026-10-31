<?php

declare(strict_types=1);

namespace PeakSim\Judge;

use PeakSim\Model\Assumption;
use PeakSim\Model\AssumptionCode;
use PeakSim\Model\Axis;
use PeakSim\Model\CostEvent;
use PeakSim\Model\Gap;
use PeakSim\Model\Site;

/**
 * Where the verdict comes from, in a fixed order: the calls that were entered, the bindings that were
 * chosen between, the events that grow, the gaps, the other assumptions. Each place is listed once.
 */
final class Evidence
{
    /**
     * @param list<Site> $trace sites in the order they were entered
     * @param list<CostEvent> $events in seq order
     * @param list<Gap> $gaps in fixed order
     * @param list<Assumption> $assumptions in fixed order
     * @return list<array{role: string, site: Site, axis: ?string, note: string}>
     */
    public static function of(array $trace, array $events, array $gaps, array $assumptions): array
    {
        $out = [];
        $seen = [];
        $add = static function (string $role, Site $site, ?string $axis, string $note) use (&$out, &$seen): void {
            $key = $role . '|' . $site->fingerprint() . '|' . ($axis ?? '') . '|' . $note;
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = ['role' => $role, 'site' => $site, 'axis' => $axis, 'note' => $note];
            }
        };

        foreach ($trace as $site) {
            $add('call', $site, null, '');
        }
        foreach ($assumptions as $a) {
            if ($a->code === AssumptionCode::WORST_CASE_OF_CANDIDATES) {
                $add('binding', $a->site, self::axisOf($a->affects), $a->message);
            }
        }
        foreach ($events as $e) {
            foreach ([Axis::Query, Axis::Memory] as $axis) {
                if ((Degree::of($axis, $e) ?? 0) >= 1) {
                    $add('event', $e->site, $axis->value, self::describe($e));
                }
            }
        }
        foreach ($gaps as $g) {
            $add('gap', $g->site, self::axisOf($g->affects), $g->code->value . ': ' . $g->message);
        }
        foreach ($assumptions as $a) {
            if ($a->code !== AssumptionCode::WORST_CASE_OF_CANDIDATES) {
                $add('assumption', $a->site, self::axisOf($a->affects), $a->code->value . ': ' . $a->message);
            }
        }

        return $out;
    }

    /** @param list<Axis> $affects */
    private static function axisOf(array $affects): ?string
    {
        $axes = array_values(array_unique(array_map(static fn (Axis $a): string => $a->value, $affects)));

        return count($axes) === 1 ? $axes[0] : null;
    }

    private static function describe(CostEvent $e): string
    {
        $loops = count($e->frames);

        return $e->kind->value . ($e->table === null ? '' : ' ' . $e->table) . ($loops === 0 ? '' : ", {$loops} loop" . ($loops === 1 ? '' : 's'));
    }
}
