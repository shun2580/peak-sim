<?php

declare(strict_types=1);

namespace PeakSim\Binding;

use PeakSim\Model\Site;

/** Candidates(list<Candidate>) | None | Unresolvable(Site, reason). */
final readonly class BindingResult
{
    /** @param list<Candidate> $candidates */
    private function __construct(
        public BindingResultKind $kind,
        public array $candidates,
        public ?Site $site,
        public ?string $reason,
    ) {
    }

    /** @param list<Candidate> $candidates in provider order, then source order */
    public static function candidates(array $candidates): self
    {
        return new self(BindingResultKind::Candidates, $candidates, null, null);
    }

    public static function none(): self
    {
        return new self(BindingResultKind::None, [], null, null);
    }

    public static function unresolvable(Site $site, string $reason): self
    {
        return new self(BindingResultKind::Unresolvable, [], $site, $reason);
    }
}
