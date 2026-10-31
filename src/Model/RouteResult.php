<?php

declare(strict_types=1);

namespace PeakSim\Model;

/** The verdict for one route. The output order lives in toArray(); the schema is docs/output-schema.md. */
final readonly class RouteResult
{
    public const KIND_CONTROLLER = 'controller';
    public const KIND_CLOSURE = 'closure';
    public const KIND_FRAMEWORK = 'framework';

    /**
     * @param list<string> $methods
     * @param string $kind controller | closure | framework
     * @param list<array<string, mixed>>|null $worstCase candidates behind a worst-case verdict
     * @param array<string, mixed> $coefficients shown only; never compared (null when it could not be derived)
     * @param list<Gap> $gaps
     * @param list<Assumption> $assumptions
     * @param list<Site> $evidence
     */
    public function __construct(
        public string $uri,
        public array $methods,
        public string $action,
        public string $kind,
        public AxisVerdict $query,
        public AxisVerdict $memory,
        public ?string $nTable,
        public ?string $mTable,
        public ?string $mPer,
        /** The lower of the two axes; null when both are unknown. */
        public ?Confidence $confidence,
        public ?CacheView $cache,
        public ?array $worstCase,
        public array $coefficients,
        public array $gaps,
        public array $assumptions,
        public array $evidence,
        /** 0 with no gap = nothing was there; 0 with a gap = could not follow. */
        public int $eventsFound,
        public bool $truncated,
    ) {
    }
}
