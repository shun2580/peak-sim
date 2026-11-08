<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Explain;

use PeakSim\Runtime\Explain\ExplainTransport;

/** A transport that records what reached it and answers with a canned plan (or throws). */
final class RecordingTransport implements ExplainTransport
{
    /** @var list<array{sql: string, bindings: list<mixed>}> */
    public array $sent = [];

    public function __construct(private readonly string $json = '{"query_block":{"select_id":1}}', private readonly ?\Throwable $fails = null)
    {
    }

    public function fetchPlanJson(string $sql, array $bindings): string
    {
        $this->sent[] = ['sql' => $sql, 'bindings' => $bindings];
        if ($this->fails !== null) {
            throw $this->fails;
        }

        return $this->json;
    }
}
