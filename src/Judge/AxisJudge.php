<?php

declare(strict_types=1);

namespace PeakSim\Judge;

use PeakSim\Model\Axis;
use PeakSim\Model\AxisVerdict;
use PeakSim\Model\Confidence;
use PeakSim\Model\CostEvent;
use PeakSim\Model\EventKind;
use PeakSim\Model\Growth;

/**
 * The verdict of one axis in one view (design 1.8). Inputs are already in their fixed order.
 *
 * A gap on the axis makes it unknown; the lower bound is only the largest degree actually found (I3).
 * Otherwise the label comes from the largest degree, medium when an assumption touches the axis.
 */
final class AxisJudge
{
    /** Which kind names the dominant site first when degrees tie. */
    public const KIND_ORDER = [
        'select' => 0, 'aggregate' => 1, 'write' => 2, 'payload' => 3, 'pdo_buffer' => 4,
        'cache_restore' => 5, 'accumulate' => 6, 'lazy_load' => 7, 'eager_load' => 8,
    ];

    public static function verdict(Axis $axis, ViewFacts $view): AxisVerdict
    {
        $deepest = 0;
        $dominant = null;
        foreach ($view->events as $e) {
            $degree = Degree::of($axis, $e);
            if ($degree === null) {
                continue;
            }
            if ($degree > $deepest || ($degree === $deepest && $degree > 0 && $dominant !== null && self::rank($e->kind) < self::rank($dominant->kind))) {
                $deepest = $degree;
                $dominant = $e;
            }
        }

        foreach ($view->gaps as $gap) {
            if (in_array($axis, $gap->affects, true)) {
                return new AxisVerdict(Growth::Unknown, $deepest, null, $deepest >= 1 ? Growth::fromDegree($deepest) : null, $gap->site);
            }
        }

        $confidence = Confidence::High;
        foreach ($view->assumptions as $assumption) {
            if (in_array($axis, $assumption->affects, true)) {
                $confidence = Confidence::Medium;
                break;
            }
        }

        return new AxisVerdict(Growth::fromDegree($deepest), $deepest, $confidence, null, $deepest >= 1 ? $dominant?->site : null);
    }

    /** The worse of two verdicts; on a tie the first (the miss view, R1). */
    public static function worse(AxisVerdict $first, AxisVerdict $second): AxisVerdict
    {
        return self::weight($second) > self::weight($first) ? $second : $first;
    }

    private static function weight(AxisVerdict $v): int
    {
        return ($v->growth === Growth::Unknown ? 1_000_000 : 0) + $v->degree;
    }

    public static function rank(EventKind $kind): int
    {
        return self::KIND_ORDER[$kind->value];
    }
}
