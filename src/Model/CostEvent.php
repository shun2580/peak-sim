<?php

declare(strict_types=1);

namespace PeakSim\Model;

/** One source of cost (a statement or a block of memory) found while walking. */
final readonly class CostEvent
{
    /**
     * @param list<Frame> $frames loops around the event, outermost first
     * @param list<ScenarioTag> $scenario
     */
    public function __construct(
        public EventKind $kind,
        public Site $site,
        public array $frames,
        public ?QueryPart $query,
        public ?MemoryPart $memory,
        public ?string $table,
        public array $scenario,
        /** Depth-first discovery number; settles ties. */
        public int $seq,
    ) {
    }

    /** The caller's loops go in front of this event's own. @param list<Frame> $outer */
    public function withOuterFrames(array $outer): self
    {
        return new self(
            $this->kind,
            $this->site,
            [...$outer, ...$this->frames],
            $this->query,
            $this->memory,
            $this->table,
            $this->scenario,
            $this->seq,
        );
    }

    /** Cache tags of the caller go in front of this event's own. @param list<ScenarioTag> $outer */
    public function withOuterScenario(array $outer): self
    {
        return new self(
            $this->kind,
            $this->site,
            $this->frames,
            $this->query,
            $this->memory,
            $this->table,
            [...$outer, ...$this->scenario],
            $this->seq,
        );
    }

    public function fingerprint(): string
    {
        return implode('|', [
            $this->kind->value,
            $this->site->fingerprint(),
            implode(',', array_map(static fn (Frame $f): string => $f->fingerprint(), $this->frames)),
            $this->query?->fingerprint() ?? '-',
            $this->memory?->fingerprint() ?? '-',
            $this->table ?? '-',
            implode(',', array_map(static fn (ScenarioTag $t): string => $t->fingerprint(), $this->scenario)),
            (string) $this->seq,
        ]);
    }
}
