<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Engine;

use PeakSim\Analysis\Type\BuilderT;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\ObjectT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\ResourceT;
use PeakSim\Analysis\Type\ShapeT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Analysis\Type\UnionT;
use PeakSim\Analysis\Type\UnknownT;
use PeakSim\Model\Cardinality;
use PeakSim\Model\CardinalityKind;
use PeakSim\Model\Delivery;

/**
 * Where two branches meet. The same kind of type is pulled to the worse side (eager loads: only what both
 * branches loaded; cardinality: a table beats a bounded list); different kinds stay side by side in a UnionT.
 * A PlainT meeting something else is taken as "null or a scalar" and disappears into the other side.
 */
final class TypeMerge
{
    public static function join(Type $a, Type $b): JoinResult
    {
        return self::joinAll([$a, $b]);
    }

    /** @param list<Type> $types */
    public static function joinAll(array $types): JoinResult
    {
        $flat = [];
        foreach ($types as $type) {
            foreach ($type instanceof UnionT ? $type->members : [$type] as $member) {
                $flat[] = $member;
            }
        }
        if ($flat === []) {
            return new JoinResult(new PlainT(), false);
        }
        foreach ($flat as $member) {
            if ($member instanceof UnknownT) {
                return new JoinResult($member, false);
            }
        }

        $groups = [];
        $lossy = false;
        $plain = null;
        foreach ($flat as $member) {
            if ($member instanceof PlainT) {
                $plain = $member;
                continue;
            }
            $key = self::kindKey($member);
            if (!isset($groups[$key])) {
                $groups[$key] = $member;
                continue;
            }
            $joined = self::joinSame($groups[$key], $member);
            $groups[$key] = $joined->type;
            $lossy = $lossy || $joined->lossy;
        }
        if ($groups === []) {
            return new JoinResult($plain ?? new PlainT(), false);
        }
        $members = array_values($groups);

        return new JoinResult(count($members) === 1 ? $members[0] : new UnionT($members), $lossy);
    }

    private static function kindKey(Type $t): string
    {
        return match (true) {
            $t instanceof ModelT => 'model:' . $t->class,
            $t instanceof BuilderT => 'builder:' . ($t->class ?? '-') . '/' . ($t->table ?? '-'),
            $t instanceof ObjectT => 'object:' . $t->class,
            $t instanceof ResourceT => 'resource:' . $t->resourceClass,
            $t instanceof ListT => 'list',
            $t instanceof ShapeT => 'shape',
            default => $t->fingerprint(),
        };
    }

    private static function joinSame(Type $a, Type $b): JoinResult
    {
        if ($a->fingerprint() === $b->fingerprint()) {
            return new JoinResult($a, false);
        }
        if ($a instanceof ModelT && $b instanceof ModelT) {
            $eager = array_values(array_intersect($a->eager, $b->eager));
            $sameOrigin = ($a->origin?->fingerprint()) === ($b->origin?->fingerprint());

            return new JoinResult(
                new ModelT($a->class, $eager, $sameOrigin ? $a->origin : null),
                $eager !== $a->eager || $eager !== $b->eager || !$sameOrigin,
            );
        }
        if ($a instanceof BuilderT && $b instanceof BuilderT) {
            $eager = array_values(array_intersect($a->eager, $b->eager));
            $relationOf = $a->relationOf === $b->relationOf ? $a->relationOf : null;

            return new JoinResult(
                new BuilderT($a->class, $a->table, $eager, $a->bounded && $b->bounded, $relationOf),
                true,
            );
        }
        if ($a instanceof ListT && $b instanceof ListT) {
            $elem = self::joinAll([$a->elem, $b->elem]);
            $card = self::worseCardinality($a->card, $b->card);
            $delivery = self::worseDelivery($a->delivery, $b->delivery);
            $window = $a->window && $b->window;

            return new JoinResult(
                new ListT($elem->type, $card, $delivery, $window),
                $elem->lossy || $card->fingerprint() !== $a->card->fingerprint() || $card->fingerprint() !== $b->card->fingerprint()
                    || $delivery !== $a->delivery || $delivery !== $b->delivery || $window !== $a->window || $window !== $b->window,
            );
        }
        if ($a instanceof ShapeT && $b instanceof ShapeT) {
            $entries = $a->entries;
            $lossy = false;
            foreach ($b->entries as $key => $type) {
                if (isset($entries[$key])) {
                    $joined = self::joinAll([$entries[$key], $type]);
                    $entries[$key] = $joined->type;
                    $lossy = $lossy || $joined->lossy;
                } else {
                    $entries[$key] = $type;
                }
            }

            return new JoinResult(new ShapeT($entries), $lossy);
        }
        if ($a instanceof ResourceT && $b instanceof ResourceT) {
            $inner = self::joinAll([$a->inner, $b->inner]);

            return new JoinResult(new ResourceT($a->resourceClass, $inner->type, $a->many || $b->many), $inner->lossy);
        }

        return new JoinResult($a, true);
    }

    private static function worseCardinality(Cardinality $a, Cardinality $b): Cardinality
    {
        $rank = static fn (Cardinality $c): int => match ($c->kind) {
            CardinalityKind::Bounded => 1,
            CardinalityKind::Table => 2,
            CardinalityKind::Unknown => 3,
        };

        return $rank($b) > $rank($a) ? $b : $a;
    }

    private static function worseDelivery(Delivery $a, Delivery $b): Delivery
    {
        $rank = static fn (Delivery $d): int => $d === Delivery::Streamed ? 1 : 2;

        return $rank($b) > $rank($a) ? $b : $a;
    }
}
