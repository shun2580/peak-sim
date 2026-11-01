<?php

declare(strict_types=1);

namespace PeakSim\Model;

/** Something the analysis could not follow. While a gap affects an axis, that axis is unknown. */
final readonly class Gap
{
    /**
     * @param list<Axis> $affects
     * @param list<ScenarioTag> $scenario
     */
    public function __construct(
        public GapCode $code,
        public Site $site,
        public string $message,
        public array $affects,
        /** How many loops surround the gap (for display only). */
        public int $loopDepth,
        public array $scenario,
    ) {
    }

    public function fingerprint(): string
    {
        return implode('|', [
            $this->code->value,
            $this->site->fingerprint(),
            implode(',', array_map(static fn (Axis $a): string => $a->value, $this->affects)),
            (string) $this->loopDepth,
            implode(',', array_map(static fn (ScenarioTag $t): string => $t->fingerprint(), $this->scenario)),
            // The message names the cause (the call that could not be followed); two causes on one line stay two gaps.
            $this->message,
        ]);
    }
}
