<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Explain;

/** What one EXPLAIN gave: the plan (the server's JSON, decoded), or the reason there is none. */
final class ExplainAnswer
{
    /** @param array<string, mixed>|null $plan */
    private function __construct(public readonly ?array $plan, public readonly ?string $unavailable)
    {
    }

    /** @param array<string, mixed> $plan */
    public static function plan(array $plan): self
    {
        return new self($plan, null);
    }

    public static function unavailable(string $reason): self
    {
        return new self(null, $reason);
    }

    public function isAvailable(): bool
    {
        return $this->plan !== null;
    }
}
