<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Explain;

/**
 * A copy of what the optimizer holds about the tables of one measurement database, as `bin/explain-at-scale` writes it
 * (read with the server's own root account, which alone can read the `mysql` schema; read-only):
 *
 *   innodb_table_stats  <table> <n_rows> <clustered_index_size> <sum_of_other_index_sizes> <last_update>
 *   innodb_index_stats  <table> <index> <stat_name> <stat_value> <sample_size> <last_update>
 *   table_options       <table> <create_options>
 *
 * A line that starts with `#` is a comment (the time of the copy) and is not part of what is compared.
 */
final class StatsSnapshot
{
    /**
     * @param array<string, string> $lines key (kind and name) => the rest of the line
     */
    private function __construct(private readonly array $lines)
    {
    }

    public static function parse(string $text): self
    {
        $lines = [];
        foreach (explode("\n", $text) as $line) {
            $line = rtrim($line, "\r");
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $cells = explode("\t", $line);
            $kind = $cells[0];
            $key = match ($kind) {
                'innodb_table_stats', 'table_options' => $kind . "\t" . ($cells[1] ?? ''),
                'innodb_index_stats' => $kind . "\t" . ($cells[1] ?? '') . "\t" . ($cells[2] ?? '') . "\t" . ($cells[3] ?? ''),
                default => null,
            };
            if ($key === null) {
                continue;
            }
            $lines[$key] = implode("\t", array_slice($cells, $kind === 'innodb_index_stats' ? 4 : 2));
        }
        ksort($lines);

        return new self($lines);
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /** Whether everything that is compared is the same in the two copies. */
    public function sameAs(self $other): bool
    {
        return $this->lines === $other->lines;
    }

    /**
     * What differs, as the number of lines that changed, appeared or went, per kind.
     *
     * @return array{changed: int, added: int, removed: int}
     */
    public function differences(self $other): array
    {
        $changed = $added = $removed = 0;
        foreach ($other->lines as $k => $v) {
            if (!array_key_exists($k, $this->lines)) {
                ++$added;
            } elseif ($this->lines[$k] !== $v) {
                ++$changed;
            }
        }
        foreach ($this->lines as $k => $_) {
            if (!array_key_exists($k, $other->lines)) {
                ++$removed;
            }
        }

        return ['changed' => $changed, 'added' => $added, 'removed' => $removed];
    }

    /** `create_options` of a table (it holds `stats_sample_pages=N` and `stats_auto_recalc=0` once they are set). */
    public function createOptions(string $table): ?string
    {
        return $this->lines["table_options\t$table"] ?? null;
    }

    /** The row of `innodb_table_stats` for a table: [n_rows, clustered_index_size, sum_of_other_index_sizes, last_update]. */
    public function tableStats(string $table): ?array
    {
        $line = $this->lines["innodb_table_stats\t$table"] ?? null;

        return $line === null ? null : explode("\t", $line);
    }

    /** The largest `sample_size` of the index statistics of a table (the pages ANALYZE TABLE sampled), or null. */
    public function maxSampleSize(string $table): ?int
    {
        $max = null;
        foreach ($this->lines as $k => $v) {
            if (!str_starts_with($k, "innodb_index_stats\t$table\t")) {
                continue;
            }
            $cells = explode("\t", $v);
            if (isset($cells[1]) && is_numeric($cells[1])) {
                $max = $max === null ? (int) $cells[1] : max($max, (int) $cells[1]);
            }
        }

        return $max;
    }

    /** The latest `last_update` over the table and its index statistics, or null. */
    public function lastUpdate(string $table): ?string
    {
        $latest = null;
        foreach ($this->lines as $k => $v) {
            $cells = explode("\t", $v);
            if ($k === "innodb_table_stats\t$table") {
                $u = $cells[3] ?? null;
            } elseif (str_starts_with($k, "innodb_index_stats\t$table\t")) {
                $u = $cells[2] ?? null;
            } else {
                continue;
            }
            if ($u !== null && ($latest === null || strcmp($u, $latest) > 0)) {
                $latest = $u;
            }
        }

        return $latest;
    }
}
