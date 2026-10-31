<?php

declare(strict_types=1);

namespace PeakSim\Model;

/** Something the analysis assumed instead of knowing. It lowers confidence to medium on the axes it affects. */
final readonly class Assumption
{
    /**
     * @param list<Axis> $affects
     * @param list<ScenarioTag> $scenario
     * @param array<array-key, mixed>|null $candidates worst case adopted: the candidates as list<string> and the adopted one under 'chosen'
     */
    public function __construct(
        public AssumptionCode $code,
        public Site $site,
        public string $message,
        public array $affects,
        public array $scenario,
        public ?array $candidates = null,
    ) {
    }

    public function fingerprint(): string
    {
        return implode('|', [
            $this->code->value,
            $this->site->fingerprint(),
            implode(',', array_map(static fn (Axis $a): string => $a->value, $this->affects)),
            implode(',', array_map(static fn (ScenarioTag $t): string => $t->fingerprint(), $this->scenario)),
        ]);
    }
}
