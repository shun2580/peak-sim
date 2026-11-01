<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Data;

/** What the generator did, table by table. No clock, no randomness: the same run gives the same report. */
final class GenerationReport
{
    /** @var array<string, TableOutcome> */
    private array $byTable = [];

    /** @param list<TableOutcome> $outcomes */
    public function __construct(public readonly string $scope, public readonly array $outcomes)
    {
        foreach ($outcomes as $outcome) {
            $this->byTable[$outcome->table] = $outcome;
        }
    }

    public function outcome(string $table): ?TableOutcome
    {
        return $this->byTable[$table] ?? null;
    }

    public function generatedCount(): int
    {
        return $this->count(TableOutcome::GENERATED);
    }

    public function failedCount(): int
    {
        return $this->count(TableOutcome::FAILED);
    }

    public function skippedCount(): int
    {
        return $this->count(TableOutcome::SKIPPED);
    }

    /** generated / (generated + failed): skipped tables are neither. An empty run counts as 1.0. */
    public function successRate(): float
    {
        $tried = $this->generatedCount() + $this->failedCount();

        return $tried === 0 ? 1.0 : $this->generatedCount() / $tried;
    }

    /** @return array<string, int> failed tables per reason code, sorted by code */
    public function reasonCounts(): array
    {
        $counts = [];
        foreach ($this->outcomes as $outcome) {
            if ($outcome->status === TableOutcome::FAILED && $outcome->reason !== null) {
                $counts[$outcome->reason] = ($counts[$outcome->reason] ?? 0) + 1;
            }
        }
        ksort($counts, SORT_STRING);

        return $counts;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $outcomes = $this->outcomes;
        usort($outcomes, static fn (TableOutcome $a, TableOutcome $b): int => $a->table <=> $b->table);

        return [
            'scope' => $this->scope,
            'generated' => $this->generatedCount(),
            'failed' => $this->failedCount(),
            'skipped' => $this->skippedCount(),
            'success_rate' => $this->successRate(),
            'reasons' => $this->reasonCounts(),
            'tables' => array_map(static fn (TableOutcome $o): array => $o->toArray(), $outcomes),
        ];
    }

    private function count(string $status): int
    {
        return count(array_filter($this->outcomes, static fn (TableOutcome $o): bool => $o->status === $status));
    }
}
