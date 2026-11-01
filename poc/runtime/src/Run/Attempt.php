<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Run;

/**
 * The result of one request against one route at one scale. One JSON line per attempt.
 *
 * `attempt` numbers the three requests of one (route, scale) measurement:
 *   1 WARMUP  framework start-up cost is paid here; not counted towards a verdict
 *   2 COLD    empty cache, fresh login: the main value
 *   3 WARM    same process again with the cache filled: the secondary value
 *
 * `queries`, `bytes` and `peakMem` are null when the request could not be measured at all.
 */
final class Attempt
{
    public const WARMUP = 1;
    public const COLD = 2;
    public const WARM = 3;

    /** The longest `detail`: a long quotation of the target app's code or output is never kept. */
    public const DETAIL_LIMIT = 200;

    public readonly ?string $detail;

    /**
     * @param array<string, int>  $byConnection queries per connection name
     * @param list<string>        $tables       table names seen in the SQL of this attempt
     */
    public function __construct(
        public readonly string $route,
        public readonly string $method,
        public readonly int $scale,
        public readonly string $axis,
        public readonly int $attempt,
        public readonly ?int $status,
        public readonly ?int $queries,
        public readonly array $byConnection,
        public readonly array $tables,
        public readonly ?int $bytes,
        public readonly ?int $peakMem,
        public readonly bool $gateBypass,
        public readonly string $class,
        ?string $detail,
    ) {
        $this->detail = $detail === null ? null : mb_substr($detail, 0, self::DETAIL_LIMIT);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'route' => $this->route,
            'method' => $this->method,
            'scale' => $this->scale,
            'axis' => $this->axis,
            'attempt' => $this->attempt,
            'status' => $this->status,
            'queries' => $this->queries,
            'by_connection' => $this->byConnection,
            'tables' => $this->tables,
            'bytes' => $this->bytes,
            'peak_mem' => $this->peakMem,
            'gate_bypass' => $this->gateBypass,
            'class' => $this->class,
            'detail' => $this->detail,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            route: (string) $data['route'],
            method: (string) $data['method'],
            scale: (int) $data['scale'],
            axis: (string) $data['axis'],
            attempt: (int) $data['attempt'],
            status: isset($data['status']) ? (int) $data['status'] : null,
            queries: isset($data['queries']) ? (int) $data['queries'] : null,
            byConnection: array_map('intval', (array) ($data['by_connection'] ?? [])),
            tables: array_map('strval', (array) ($data['tables'] ?? [])),
            bytes: isset($data['bytes']) ? (int) $data['bytes'] : null,
            peakMem: isset($data['peak_mem']) ? (int) $data['peak_mem'] : null,
            gateBypass: (bool) ($data['gate_bypass'] ?? false),
            class: (string) $data['class'],
            detail: isset($data['detail']) ? (string) $data['detail'] : null,
        );
    }
}
