<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Type;

/** The candidates when a branch changes the type. Beyond MAX_MEMBERS the caller makes a UNION_TOO_LARGE gap. */
final class UnionT extends Type
{
    public const MAX_MEMBERS = 8;

    /** @var list<Type> sorted by fingerprint, without duplicates */
    public readonly array $members;

    /** @param list<Type> $members */
    public function __construct(array $members)
    {
        $byPrint = [];
        foreach ($members as $member) {
            $byPrint[$member->fingerprint()] = $member;
        }
        ksort($byPrint, SORT_STRING);
        $this->members = array_values($byPrint);
    }

    public function withMember(Type $member): self
    {
        return new self([...$this->members, $member]);
    }

    public function isTooLarge(): bool
    {
        return count($this->members) > self::MAX_MEMBERS;
    }

    public function fingerprint(): string
    {
        return 'union(' . implode('|', array_map(static fn (Type $t): string => $t->fingerprint(), $this->members)) . ')';
    }
}
