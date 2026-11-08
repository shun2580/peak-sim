<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Explain;

use PeakSim\Runtime\Config\MeasureRefused;

/**
 * The only way a statement from a capture file reaches the server. It takes the representative SQL of a type (without
 * `EXPLAIN`), puts `EXPLAIN FORMAT=JSON ` in front of it itself, and gives the whole text to `ExplainGate` before the
 * transport sees it. `sendRaw` is the same door for a text that is already complete: the gate decides there too.
 *
 * The answer is an `ExplainAnswer`: the plan, or the reason it could not be taken (`explain_unavailable`). A refusal of
 * the gate is not an answer: it is thrown (`MeasureRefused`, exit code 10) and the run stops.
 */
final class ExplainSender
{
    private int $sent = 0;

    public function __construct(private readonly ExplainTransport $transport)
    {
    }

    /**
     * @param list<mixed> $bindings
     *
     * @throws MeasureRefused
     */
    public function explain(string $representativeSql, array $bindings): ExplainAnswer
    {
        return $this->sendRaw(ExplainGate::PREFIX . $representativeSql, $bindings);
    }

    /**
     * @param list<mixed> $bindings
     *
     * @throws MeasureRefused
     */
    public function sendRaw(string $fullSql, array $bindings): ExplainAnswer
    {
        ExplainGate::assertSendable($fullSql);
        $placeholders = self::placeholders($fullSql);
        if ($placeholders !== count($bindings)) {
            return ExplainAnswer::unavailable(sprintf('binding_count_mismatch: the statement has %d placeholders, the capture has %d values', $placeholders, count($bindings)));
        }
        ++$this->sent;
        try {
            $json = $this->transport->fetchPlanJson($fullSql, $bindings);
        } catch (\Throwable $e) {
            return ExplainAnswer::unavailable('server_refused: ' . mb_substr($e->getMessage(), 0, 200));
        }
        $plan = json_decode($json, true);
        if (!is_array($plan)) {
            return ExplainAnswer::unavailable('plan_not_json');
        }

        return ExplainAnswer::plan($plan);
    }

    /** How many statements went to the transport (those the gate let through). */
    public function sent(): int
    {
        return $this->sent;
    }

    /** `?` placeholders outside quoted texts, quoted names and comments. */
    public static function placeholders(string $sql): int
    {
        return substr_count(ExplainGate::codeOnly($sql), '?');
    }
}
