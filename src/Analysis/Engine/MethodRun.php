<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Engine;

use PeakSim\Model\Assumption;
use PeakSim\Model\CostEvent;
use PeakSim\Model\Frame;
use PeakSim\Model\Gap;
use PeakSim\Model\GapCode;
use PeakSim\Model\ScenarioTag;
use PeakSim\Model\Site;
use PeakSim\Model\Summary;

/**
 * Everything one method body produced while it was walked, plus the loop and cache stacks around the position.
 * Events carry frames relative to the method's entry; the caller adds its own frames when it absorbs the summary.
 */
final class MethodRun
{
    /** @var list<CostEvent> */
    public array $events = [];

    /** @var list<Gap> */
    public array $gaps = [];

    /** @var list<Assumption> */
    public array $assumptions = [];

    /** @var list<Site> */
    public array $trace = [];

    /** @var list<Frame> */
    public array $frames = [];

    /** @var list<ScenarioTag> */
    public array $scenario = [];

    public bool $poisoned = false;

    public bool $truncated = false;

    /** The deepest absolute call level reached by this run or anything it absorbed. */
    public int $deepest;

    private int $seq = 0;

    private int $activity = 0;

    /** @var array<string, true> */
    private array $gapIndex = [];

    /** @var array<string, true> */
    private array $assumptionIndex = [];

    public function __construct(
        public readonly int $level,
        public readonly string $file,
    ) {
        $this->deepest = $level;
    }

    public function nextSeq(): int
    {
        return $this->seq++;
    }

    /** Grows with every event or gap, even a repeated one; a loop with growth in its body has a body that did something. */
    public function activity(): int
    {
        return $this->activity;
    }

    public function addEvent(CostEvent $event): void
    {
        $this->activity++;
        $this->events[] = $event;
    }

    public function addGap(Gap $gap): void
    {
        $this->activity++;
        if (in_array($gap->code, [GapCode::DEPTH_LIMIT, GapCode::RECURSION, GapCode::BUDGET_EXCEEDED], true)) {
            $this->poisoned = true;
        }
        if ($gap->code === GapCode::DEPTH_LIMIT) {
            $this->truncated = true;
        }
        $key = $gap->fingerprint();
        if (isset($this->gapIndex[$key])) {
            return;
        }
        $this->gapIndex[$key] = true;
        $this->gaps[] = $gap;
    }

    public function addAssumption(Assumption $assumption): void
    {
        $key = $assumption->fingerprint() . '|' . json_encode($assumption->candidates);
        if (isset($this->assumptionIndex[$key])) {
            return;
        }
        $this->assumptionIndex[$key] = true;
        $this->assumptions[] = $assumption;
    }

    /** Take in a callee's summary at the current loops and cache tags. */
    public function absorb(Summary $summary, int $deepest, bool $poisoned): void
    {
        foreach ($summary->events as $e) {
            $this->addEvent(new CostEvent(
                $e->kind,
                $e->site,
                [...$this->frames, ...$e->frames],
                $e->query,
                $e->memory,
                $e->table,
                [...$this->scenario, ...$e->scenario],
                $this->nextSeq(),
            ));
        }
        $depth = count($this->frames);
        foreach ($summary->gaps as $g) {
            $this->addGap(new Gap($g->code, $g->site, $g->message, $g->affects, $g->loopDepth + $depth, [...$this->scenario, ...$g->scenario]));
        }
        foreach ($summary->assumptions as $a) {
            $this->addAssumption(new Assumption($a->code, $a->site, $a->message, $a->affects, [...$this->scenario, ...$a->scenario], $a->candidates));
        }
        foreach ($summary->trace as $site) {
            $this->trace[] = $site;
        }
        $this->truncated = $this->truncated || $summary->truncated;
        $this->poisoned = $this->poisoned || $poisoned;
        $this->deepest = max($this->deepest, $deepest);
    }
}
