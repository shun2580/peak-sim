<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Load;

/**
 * What the first stage hands on: plain arrays, JSON-safe (written as `loaded.json`). Per scenario: `id`, `kind`, `tables`, `observed` (a calibration
 * scenario only), `unknown_tables`, `zero_rps_routes`, and `routes` (the routes with an rps above 0, each with its `n`, `cost` `ok`|`unknown`, `queries`, `rows`,
 * `rows_upper`, `peak_memory`, `types`; see {@see EstimateFormulas::evaluate}). A target scenario carries no `observed`.
 */
final class LoadedInput
{
    public const SCHEMA_VERSION = '0.1';

    /**
     * @param array{cores: int|float, threshold: float, php_memory_limit_bytes: int|null, app_cpu_time_limit_s?: float, app_memory_limit_bytes?: int} $resources
     * @param list<array<string, mixed>>                                                    $scenarios
     */
    public function __construct(
        public readonly ?string $app,
        public readonly int $estimateAt,
        public readonly array $resources,
        public readonly array $scenarios,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'app' => $this->app,
            'estimate_at' => $this->estimateAt,
            'resources' => $this->resources,
            'scenarios' => $this->scenarios,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
}
