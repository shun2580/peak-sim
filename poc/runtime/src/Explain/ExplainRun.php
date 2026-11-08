<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Explain;

/**
 * Asks the server for the plan of every statement type of a set of routes, once, in the state the database is in now.
 * Nothing is run: every statement goes through `ExplainSender` (so through `ExplainGate`) as `EXPLAIN FORMAT=JSON <statement>`.
 *
 * Kinds that cannot be explained (BEGIN, SAVEPOINT, SET, ...) are not sent at all and are recorded as unavailable with the kind.
 * A text and its values that two types share are asked once. A refusal of the gate stops the run (`MeasureRefused`, exit 10).
 */
final class ExplainRun
{
    /**
     * @param list<array{route: string, id: string, kind: string, pattern: string, sql: ?string, bindings: list<mixed>, bindings_redacted: bool, representative_scale: ?int, not_explained: ?string}> $statements
     * @param list<string> $routes the routes of this database
     *
     * @return list<array{route: string, id: string, plan: ?array<string, mixed>, unavailable: ?string}>
     *
     * @throws \PeakSim\Runtime\Config\MeasureRefused
     */
    public static function run(ExplainSender $sender, array $statements, array $routes): array
    {
        $inGroup = array_fill_keys($routes, true);
        $asked = [];
        $out = [];
        foreach ($statements as $s) {
            if (!isset($inGroup[$s['route']])) {
                continue;
            }
            if ($s['not_explained'] !== null || $s['sql'] === null) {
                $out[] = ['route' => $s['route'], 'id' => $s['id'], 'plan' => null, 'unavailable' => (string) ($s['not_explained'] ?? 'no_representative_in_the_capture')];
                continue;
            }
            if (!ExplainGate::isExplainableKind($s['sql'])) {
                $out[] = ['route' => $s['route'], 'id' => $s['id'], 'plan' => null, 'unavailable' => 'not_explainable_kind: ' . ($s['kind'] !== '' ? $s['kind'] : 'unknown')];
                continue;
            }
            $hash = sha1($s['sql'] . "\0" . json_encode($s['bindings'], JSON_INVALID_UTF8_SUBSTITUTE));
            $asked[$hash] ??= $sender->explain($s['sql'], $s['bindings']);
            $answer = $asked[$hash];
            $out[] = ['route' => $s['route'], 'id' => $s['id'], 'plan' => $answer->plan, 'unavailable' => $answer->unavailable];
        }

        return $out;
    }
}
