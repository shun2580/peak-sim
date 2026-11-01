<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Data;

use InvalidArgumentException;

/**
 * How many rows each table gets at one scale: {table => rows}.
 *
 * Two shapes (design 1.2.2):
 *  - targeted: the static analysis named the table(s) that grow (n_table), and perhaps a table that grows
 *    per row of another (m_table, m_per). The n tables get N rows; the m table gets M rows per row of m_per;
 *    everyone else gets the small fixed base B.
 *  - global: no candidate; every table gets N rows (the "fixed" tables, such as roles, stay at B). A result
 *    measured this way says how the cost grows with the size of the whole database, nothing finer.
 *
 * The static constructors know no table list, so rows() holds only the explicit entries; rowsFor() answers for
 * any table, and resolve() produces the full map for a schema, after making room for one-to-one and pivot
 * unique foreign keys (a unique foreign key needs at least as many parent rows as there are child rows).
 */
final class ScalePlan
{
    private const MAX_RESOLVE_ROUNDS = 64;

    /**
     * @param array<string, int> $explicit
     * @param list<string>       $nTables
     */
    private function __construct(
        private readonly string $scope,
        private readonly array $explicit,
        private readonly int $default,
        private readonly array $nTables,
    ) {
    }

    /**
     * @param list<string> $nTables tables that get N rows (n_table and its equally good alternatives measured together)
     * @param list<string> $fixedTables
     */
    public static function targeted(array $nTables, ?string $mTable, ?string $mPer, int $n, int $m, int $base): self
    {
        $nTables = array_values(array_unique($nTables));
        sort($nTables, SORT_STRING);
        if ($nTables === []) {
            throw new InvalidArgumentException('targeted plan needs at least one n table (use global() when there is none)');
        }
        self::positive($n, 'n');
        self::positive($base, 'base');

        $explicit = array_fill_keys($nTables, $n);
        if ($mTable !== null) {
            if ($mPer === null) {
                throw new InvalidArgumentException('an m table needs the table it is counted per (m_per)');
            }
            self::positive($m, 'm');
            if (in_array($mTable, $nTables, true)) {
                throw new InvalidArgumentException('the m table cannot also be an n table');
            }
            $explicit[$mTable] = $m * ($explicit[$mPer] ?? $base);
        }
        ksort($explicit, SORT_STRING);

        return new self('targeted:' . implode(',', $nTables), $explicit, $base, $nTables);
    }

    /** @param list<string> $fixedTables tables that stay at the base size (reference tables with a handful of rows) */
    public static function global(int $n, int $base, array $fixedTables = []): self
    {
        self::positive($n, 'n');
        self::positive($base, 'base');
        $fixed = array_values(array_unique($fixedTables));
        sort($fixed, SORT_STRING);

        return new self('global', array_fill_keys($fixed, $base), $n, []);
    }

    /** "targeted:<n tables>" or "global": the scope every result measured with this plan must carry. */
    public function scope(): string
    {
        return $this->scope;
    }

    /** @return array<string, int> the explicit entries only (see the class comment) */
    public function rows(): array
    {
        return $this->explicit;
    }

    public function rowsFor(string $table): int
    {
        return $this->explicit[$table] ?? $this->default;
    }

    /**
     * The full {table => rows} map for a schema, with parents raised where a unique foreign key needs it.
     * Never lowers a table.
     *
     * @return array<string, int> in the schema's (sorted) table order
     */
    public function resolve(DatabaseSchema $schema): array
    {
        $rows = [];
        foreach ($schema->names() as $name) {
            $rows[$name] = $this->rowsFor($name);
        }

        for ($round = 0; $round < self::MAX_RESOLVE_ROUNDS; $round++) {
            $changed = false;
            foreach ($schema->tables() as $table) {
                $coverers = self::uniqueForeignKeyCoverers($table);
                foreach ($coverers as $parents) {
                    $parents = array_values(array_unique(array_filter($parents, static fn (string $p): bool => $p !== $table->name && isset($rows[$p]))));
                    if ($parents === []) {
                        continue;
                    }
                    $need = self::smallestRoot($rows[$table->name], count($parents));
                    foreach ($parents as $parent) {
                        if ($rows[$parent] < $need) {
                            $rows[$parent] = $need;
                            $changed = true;
                        }
                    }
                }
            }
            if (!$changed) {
                break;
            }
        }

        return $rows;
    }

    /**
     * For each unique index (and the primary key) made only of foreign key columns: the parent tables.
     *
     * @return list<list<string>>
     */
    public static function uniqueForeignKeyCoverers(TableSchema $table): array
    {
        $out = [];
        foreach ([$table->primaryKey, ...$table->uniqueIndexes] as $index) {
            $keys = self::coveringForeignKeys($table, $index);
            if ($keys !== null) {
                $out[] = array_map(static fn (ForeignKey $fk): string => $fk->refTable, $keys);
            }
        }

        return $out;
    }

    /**
     * The foreign keys that lie fully inside $index and together cover every column of it, or null when some
     * column of the index is not part of such a key (then the other columns keep the rows apart).
     *
     * @param list<string> $index
     *
     * @return ?list<ForeignKey> in the table's key order
     */
    public static function coveringForeignKeys(TableSchema $table, array $index): ?array
    {
        if ($index === []) {
            return null;
        }
        $covered = [];
        $keys = [];
        foreach ($table->foreignKeys as $fk) {
            if (array_diff($fk->columns, $index) === []) {
                foreach ($fk->columns as $column) {
                    $covered[$column] = true;
                }
                $keys[] = $fk;
            }
        }

        return count($covered) === count($index) && $keys !== [] ? $keys : null;
    }

    /** Smallest integer t with t^k >= $rows. */
    private static function smallestRoot(int $rows, int $k): int
    {
        $t = max(1, (int) floor($rows ** (1 / $k)));
        while ($t ** $k < $rows) {
            $t++;
        }
        while ($t > 1 && ($t - 1) ** $k >= $rows) {
            $t--;
        }

        return $t;
    }

    private static function positive(int $value, string $name): void
    {
        if ($value < 1) {
            throw new InvalidArgumentException(sprintf('%s must be at least 1', $name));
        }
    }
}
