<?php

declare(strict_types=1);

namespace PeakSim\Judge;

use PeakSim\Model\Assumption;
use PeakSim\Model\CostEvent;
use PeakSim\Model\Gap;
use PeakSim\Model\ScenarioTag;

/** The events, gaps and assumptions that belong to one view of the route. */
final readonly class ViewFacts
{
    /**
     * @param list<CostEvent> $events
     * @param list<Gap> $gaps
     * @param list<Assumption> $assumptions
     */
    public function __construct(
        public array $events,
        public array $gaps,
        public array $assumptions,
    ) {
    }

    /**
     * An item belongs to a side ('miss' or 'hit') when every cache tag it carries is on that side;
     * an untagged item belongs to both.
     *
     * @param list<CostEvent> $events
     * @param list<Gap> $gaps
     * @param list<Assumption> $assumptions
     */
    public static function side(string $side, array $events, array $gaps, array $assumptions): self
    {
        return new self(
            array_values(array_filter($events, static fn (CostEvent $e): bool => self::in($side, $e->scenario))),
            array_values(array_filter($gaps, static fn (Gap $g): bool => self::in($side, $g->scenario))),
            array_values(array_filter($assumptions, static fn (Assumption $a): bool => self::in($side, $a->scenario))),
        );
    }

    /** @param list<ScenarioTag> $tags */
    private static function in(string $side, array $tags): bool
    {
        foreach ($tags as $tag) {
            if ($tag->side !== $side) {
                return false;
            }
        }

        return true;
    }
}
