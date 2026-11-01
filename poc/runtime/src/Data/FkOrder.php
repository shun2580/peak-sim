<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Data;

/**
 * Orders tables parents-first by their foreign keys (design 1.2.2). A cycle is broken at a foreign key whose
 * columns are all nullable: that key is inserted as NULL and filled in afterwards ("deferred"). Cycles with no
 * nullable key cannot be generated (fk_cycle); tables that depend on them are "blocked".
 *
 * Deterministic: ties are broken by table name, and edges are tried in (table, key name) order.
 */
final class FkOrder
{
    /**
     * @param list<string>                                          $order    parents first
     * @param list<array{table: string, fk: ForeignKey}>            $deferred foreign keys inserted as NULL and filled later
     * @param list<string>                                          $cyclic   tables inside a cycle that cannot be broken
     * @param list<string>                                          $blocked  tables that need a cyclic table
     */
    private function __construct(
        public readonly array $order,
        public readonly array $deferred,
        public readonly array $cyclic,
        public readonly array $blocked,
    ) {
    }

    /** @param list<string> $tables the tables taking part in the run */
    public static function sort(DatabaseSchema $schema, array $tables): self
    {
        $tables = array_values(array_unique($tables));
        sort($tables, SORT_STRING);
        $inSet = array_fill_keys($tables, true);

        /** @var list<array{table: string, fk: ForeignKey}> $edges */
        $edges = [];
        foreach ($tables as $name) {
            $table = $schema->table($name);
            if ($table === null) {
                continue;
            }
            foreach ($table->foreignKeys as $fk) {
                if (!isset($inSet[$fk->refTable])) {
                    continue;
                }
                $edge = ['table' => $name, 'fk' => $fk];
                if ($fk->refTable === $name && self::nullable($schema, $edge)) {
                    continue; // a row may point at an earlier row of its own table inside one INSERT: no ordering, no deferral
                }
                $edges[] = $edge;
            }
        }
        usort($edges, static fn (array $a, array $b): int => [$a['table'], $a['fk']->name] <=> [$b['table'], $b['fk']->name]);

        $deferred = [];
        $deferredKeys = [];
        while (true) {
            $active = array_values(array_filter($edges, static fn (array $e): bool => !isset($deferredKeys[$e['table'] . "\0" . $e['fk']->name])));
            [$order, $stuck] = self::kahn($tables, $active);
            if ($stuck === []) {
                return new self($order, $deferred, [], []);
            }

            $stuckSet = array_fill_keys($stuck, true);
            // only a key that lies on a cycle is worth deferring (not one of a table that merely depends on the cycle)
            $pick = null;
            foreach ($active as $edge) {
                if (isset($stuckSet[$edge['table']], $stuckSet[$edge['fk']->refTable]) && self::nullable($schema, $edge)
                    && self::reaches($edge['fk']->refTable, $edge['table'], $active, $stuckSet)) {
                    $pick = $edge;
                    break;
                }
            }
            if ($pick === null) {
                $cyclic = self::cycleMembers($stuck, $active);
                $blocked = array_values(array_diff($stuck, $cyclic));

                return new self($order, $deferred, $cyclic, $blocked);
            }
            $deferred[] = $pick;
            $deferredKeys[$pick['table'] . "\0" . $pick['fk']->name] = true;
        }
    }

    /**
     * @param list<string>                                  $tables sorted
     * @param list<array{table: string, fk: ForeignKey}>    $edges  child => parent
     *
     * @return array{0: list<string>, 1: list<string>} [order, tables left over]
     */
    private static function kahn(array $tables, array $edges): array
    {
        $waiting = array_fill_keys($tables, 0);
        $children = [];
        foreach ($edges as $edge) {
            if ($edge['table'] === $edge['fk']->refTable) {
                $waiting[$edge['table']]++; // a self reference that is not deferred can never be satisfied
                continue;
            }
            $waiting[$edge['table']]++;
            $children[$edge['fk']->refTable][] = $edge['table'];
        }

        $order = [];
        $ready = array_keys(array_filter($waiting, static fn (int $n): bool => $n === 0));
        while ($ready !== []) {
            sort($ready, SORT_STRING);
            $next = array_shift($ready);
            $order[] = $next;
            foreach ($children[$next] ?? [] as $child) {
                if (--$waiting[$child] === 0) {
                    $ready[] = $child;
                }
            }
        }
        $stuck = array_values(array_diff($tables, $order));
        sort($stuck, SORT_STRING);

        return [$order, $stuck];
    }

    /**
     * Can $from reach $to by following keys (child to parent) among the stuck tables?
     *
     * @param list<array{table: string, fk: ForeignKey}> $edges
     * @param array<string, true>                       $within
     */
    private static function reaches(string $from, string $to, array $edges, array $within): bool
    {
        $seen = [];
        $queue = [$from];
        while ($queue !== []) {
            $node = array_shift($queue);
            if ($node === $to) {
                return true;
            }
            if (isset($seen[$node])) {
                continue;
            }
            $seen[$node] = true;
            foreach ($edges as $edge) {
                if ($edge['table'] === $node && isset($within[$edge['fk']->refTable])) {
                    $queue[] = $edge['fk']->refTable;
                }
            }
        }

        return false;
    }

    /** @param array{table: string, fk: ForeignKey} $edge */
    private static function nullable(DatabaseSchema $schema, array $edge): bool
    {
        $table = $schema->table($edge['table']);
        if ($table === null) {
            return false;
        }
        foreach ($edge['fk']->columns as $name) {
            $column = $table->column($name);
            if ($column === null || !$column->nullable) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string>                                  $stuck
     * @param list<array{table: string, fk: ForeignKey}>    $edges
     *
     * @return list<string> tables that can reach themselves through the remaining edges
     */
    private static function cycleMembers(array $stuck, array $edges): array
    {
        $stuckSet = array_fill_keys($stuck, true);
        $next = [];
        foreach ($edges as $edge) {
            if (isset($stuckSet[$edge['table']], $stuckSet[$edge['fk']->refTable])) {
                $next[$edge['table']][] = $edge['fk']->refTable;
            }
        }

        $members = [];
        foreach ($stuck as $start) {
            $seen = [];
            $queue = $next[$start] ?? [];
            while ($queue !== []) {
                $node = array_shift($queue);
                if ($node === $start) {
                    $members[] = $start;
                    break;
                }
                if (isset($seen[$node])) {
                    continue;
                }
                $seen[$node] = true;
                foreach ($next[$node] ?? [] as $n) {
                    $queue[] = $n;
                }
            }
        }

        return $members;
    }
}
