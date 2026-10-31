<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Engine;

use PeakSim\Model\Axis;
use PeakSim\Model\Frame;
use PeakSim\Model\Summary;

/**
 * Degrees of a summary per axis, with the same formula the judge uses (design 1.5). The engine needs them only
 * to see whether the candidates of a binding disagree; the verdict itself is the judge's.
 */
final class CandidateComparison
{
    /** @return array{query: int, memory: int} */
    public static function degrees(Summary $summary): array
    {
        $query = 0;
        $memory = 0;
        foreach ($summary->events as $e) {
            if ($e->query !== null) {
                $query = max($query, count($e->frames) + ($e->query->ownUnbounded ? 1 : 0));
            }
            if ($e->memory !== null) {
                $counted = $e->memory->retainFrom !== null
                    ? max(0, count($e->frames) - $e->memory->retainFrom)
                    : count(array_filter($e->frames, static fn (Frame $f): bool => !$f->streamed));
                $memory = max($memory, $counted + $e->memory->ownDegree);
            }
        }

        return ['query' => $query, 'memory' => $memory];
    }

    /**
     * @param array<string, Summary> $byCandidate summary per candidate class
     * @return array{axes: list<Axis>, chosen: string|null}|null null when the candidates agree
     */
    public static function disagreement(array $byCandidate): ?array
    {
        if (count($byCandidate) < 2) {
            return null;
        }
        $axes = [];
        $max = [];
        $chosen = null;
        $chosenScore = -1;
        foreach (['query' => Axis::Query, 'memory' => Axis::Memory] as $name => $axis) {
            $values = array_map(static fn (Summary $s): int => self::degrees($s)[$name], $byCandidate);
            if (count(array_unique($values)) > 1) {
                $axes[] = $axis;
            }
            $max[$name] = max($values);
        }
        foreach ($byCandidate as $class => $summary) {
            $d = self::degrees($summary);
            $score = $d['query'] * 1000 + $d['memory'];
            if ($score > $chosenScore) {
                $chosenScore = $score;
                $chosen = $class;
            }
        }

        return $axes === [] ? null : ['axes' => $axes, 'chosen' => $chosen];
    }
}
