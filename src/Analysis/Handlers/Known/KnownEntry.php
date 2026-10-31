<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Handlers\Known;

/**
 * One line of the allow-list: either an answer (what the call returns and on what grounds) or a hand-over to the
 * handler that owns the call.
 */
final readonly class KnownEntry
{
    public const BASIS_VERIFIED = 'verified';
    public const BASIS_CONTRACT = 'contract';

    public function __construct(
        /** 'plain' | 'object:<class>' | 'arg:<n>'; null for a delegated entry */
        public ?string $returns,
        public int $argsMin,
        /** null = any number of arguments */
        public ?int $argsMax,
        public bool $storesClosures,
        /** 'verified' | 'contract'; null for a delegated entry */
        public ?string $basis,
        public string $why,
        /** The handler that owns the call; null for an answered entry */
        public ?string $delegate = null,
    ) {
    }

    public function isDelegated(): bool
    {
        return $this->delegate !== null;
    }
}
