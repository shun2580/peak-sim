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
 *    everyone else gets the small fixed base B. N may be 0: the n table is empty, and so is every table that
 *    cannot hold a row without a row of it (a NOT NULL foreign key); the response of that empty database is the
 *    reference for "is the response empty at every scale".
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
        if ($n < 0) {
            throw new InvalidArgumentException('n must be at least 0');
        }
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
     * @param list<MorphLink> $morphs polymorphic relations the app declares: a child table with no key to its parents,
     *                                which is raised like a link table (see growLinkTables)
     *
     * @return array<string, int> in the schema's (sorted) table order
     */
    public function resolve(DatabaseSchema $schema, array $morphs = []): array
    {
        $rows = [];
        foreach ($schema->names() as $name) {
            $rows[$name] = $this->rowsFor($name);
        }
        self::emptyWhatNeedsEmptyTables($schema, $rows);
        $this->growLinkTables($schema, $rows, $morphs);

        for ($round = 0; $round < self::MAX_RESOLVE_ROUNDS; $round++) {
            $changed = false;
            foreach ($schema->tables() as $table) {
                $coverers = self::uniqueForeignKeyCoverers($table);
                foreach ($coverers as $parents) {
                    // an empty table stays empty on purpose (N = 0); it is never raised for a unique key
                    $parents = array_values(array_unique(array_filter($parents, static fn (string $p): bool => $p !== $table->name && ($rows[$p] ?? 0) > 0)));
                    if ($parents === [] || $rows[$table->name] === 0) {
                        continue;
                    }
                    $reach = 1;
                    foreach ($parents as $parent) {
                        $reach = min(PHP_INT_MAX, $reach * max(1, $rows[$parent]));
                    }
                    if ($reach >= $rows[$table->name]) {
                        continue; // the parents already hold enough distinct combinations
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
     * A link table (its key is made of foreign keys to two or more other tables, like a pivot of a many-to-many
     * relation) that the plan does not name holds one link per row of its largest parent, never fewer than it was
     * given. Left at the base size it would tie only a handful of the grown rows to anything, and what is reached
     * through it would not grow with the table that grows (a route that lists the children of one parent would read
     * the same few rows at every scale). A table the plan names keeps its own count; an empty table stays empty.
     * Repeated, so that a link table of link tables grows too.
     *
     * A table that is the child side of a polymorphic relation is treated the same way: it is tied to its parents
     * by a type and an id, not by a key, but what is reached through it must grow with them all the same.
     *
     * @param array<string, int> $rows
     * @param list<MorphLink>    $morphs
     */
    private function growLinkTables(DatabaseSchema $schema, array &$rows, array $morphs): void
    {
        $morphParents = [];
        foreach ($morphs as $link) {
            if ($schema->table($link->parentTable) !== null) {
                $morphParents[$link->table][$link->parentTable] = true;
            }
        }
        for ($round = 0; $round < self::MAX_RESOLVE_ROUNDS; $round++) {
            $changed = false;
            foreach ($schema->tables() as $table) {
                if (array_key_exists($table->name, $this->explicit) || ($rows[$table->name] ?? 0) === 0) {
                    continue;
                }
                $largest = 0;
                foreach (self::uniqueForeignKeyCoverers($table) as $parents) {
                    $parents = array_values(array_unique(array_filter($parents, static fn (string $p): bool => $p !== $table->name)));
                    if (count($parents) < 2) {
                        continue;
                    }
                    foreach ($parents as $parent) {
                        $largest = max($largest, $rows[$parent] ?? 0);
                    }
                }
                foreach (array_keys($morphParents[$table->name] ?? []) as $parent) {
                    if ($parent !== $table->name) {
                        $largest = max($largest, $rows[$parent] ?? 0);
                    }
                }
                if ($largest > $rows[$table->name]) {
                    $rows[$table->name] = $largest;
                    $changed = true;
                }
            }
            if (!$changed) {
                break;
            }
        }
    }

    /**
     * A table with a NOT NULL foreign key to an empty table cannot have rows: it is emptied too (repeatedly, so a
     * chain of tables empties together). A key whose columns are all nullable stays NULL instead.
     *
     * @param array<string, int> $rows
     */
    private static function emptyWhatNeedsEmptyTables(DatabaseSchema $schema, array &$rows): void
    {
        do {
            $changed = false;
            foreach ($schema->tables() as $table) {
                if ($rows[$table->name] === 0) {
                    continue;
                }
                foreach ($table->foreignKeys as $fk) {
                    if ($fk->refTable === $table->name || ($rows[$fk->refTable] ?? 1) > 0) {
                        continue;
                    }
                    $nullable = true;
                    foreach ($fk->columns as $name) {
                        $nullable = $nullable && ($table->column($name)?->nullable ?? false);
                    }
                    if (!$nullable) {
                        $rows[$table->name] = 0;
                        $changed = true;
                        break;
                    }
                }
            }
        } while ($changed);
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
