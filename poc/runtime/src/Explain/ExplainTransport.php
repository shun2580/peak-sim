<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Explain;

/**
 * The wire under `ExplainSender`: sends ONE already-gated statement with its bound values and returns the first column of
 * the first row (EXPLAIN FORMAT=JSON gives one row with one column: the plan as JSON text). Nothing else is asked of it;
 * the gate is not its job (`ExplainSender` calls `ExplainGate` before it).
 */
interface ExplainTransport
{
    /**
     * @param list<mixed> $bindings
     *
     * @throws \Throwable the server's refusal or a lost connection
     */
    public function fetchPlanJson(string $sql, array $bindings): string;
}
