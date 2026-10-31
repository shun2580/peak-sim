<?php

declare(strict_types=1);

namespace PeakSim\Binding;

use PeakSim\Model\Site;

/** One registration found in a provider: its candidates, or the reason they could not be read. */
final readonly class BindingEntry
{
    /** The consumer of a contextual binding whose `when()` argument is not a constant: it matches every consumer. */
    public const ANY_CONSUMER = '*';

    /** @param list<Candidate> $candidates */
    private function __construct(
        public string $abstract,
        public ?string $consumer,
        public array $candidates,
        public ?Site $failedAt,
        public ?string $failure,
    ) {
    }

    /** @param list<Candidate> $candidates */
    public static function of(string $abstract, ?string $consumer, array $candidates): self
    {
        return new self($abstract, $consumer, $candidates, null, null);
    }

    public static function unreadable(string $abstract, ?string $consumer, Site $at, string $failure): self
    {
        return new self($abstract, $consumer, [], $at, $failure);
    }

    public function isReadable(): bool
    {
        return $this->failure === null;
    }

    public function key(): string
    {
        $parts = [$this->abstract, (string) $this->consumer, (string) $this->failure];
        foreach ($this->candidates as $c) {
            $parts[] = $c->kind . ':' . $c->impl . '@' . $c->site->fingerprint() . ($c->conditional ? '?' : '');
        }

        return implode('|', $parts);
    }
}
