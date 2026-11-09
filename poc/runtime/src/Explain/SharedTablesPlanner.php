<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Explain;

use PeakSim\Runtime\Measure\DatabasePoint;
use PeakSim\Runtime\Measure\DataPlanner;
use PeakSim\Runtime\Measure\GeneratorPlanner;
use PeakSim\Runtime\Measure\Sweep;
use PeakSim\Runtime\Route\RouteSpec;

/**
 * One database for every route: the tables named by the caller all get N rows together and every other table the base size.
 *
 * It is for plans taken on the same kind of database a shared test bed holds, where several routes use one database and the tables
 * they scale on grow at the same time. The default planner (`GeneratorPlanner`) gives each route the database of its own scaled
 * table; this one is used only when the caller names the tables (`bin/explain-at-scale --n-tables=`). It has one axis, N, and no
 * children-per-parent axis.
 */
final class SharedTablesPlanner implements DataPlanner
{
    private const TAG = 'shared';

    /** @var list<string> */
    private readonly array $tables;

    /** @param list<string> $tables bare table names, written as the database has them (lowercase letters, digits, _) */
    public function __construct(array $tables, private readonly int $base = GeneratorPlanner::BASE)
    {
        foreach ($tables as $table) {
            if (!is_string($table) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $table) !== 1) {
                throw new \InvalidArgumentException('a table name is lowercase letters, digits and _, starting with a letter');
            }
        }
        $tables = array_values(array_unique($tables));
        if ($tables === []) {
            throw new \InvalidArgumentException('name at least one table');
        }
        sort($tables, SORT_STRING);
        $this->tables = $tables;
    }

    public function sweepsFor(RouteSpec $spec, array $scales): array
    {
        return [new Sweep('N', array_map(
            fn (int $n): DatabasePoint => new DatabasePoint(self::TAG, $n, [
                'mode' => 'targeted', 'n_tables' => $this->tables, 'm_table' => null, 'm_per' => null, 'n' => $n, 'm' => 0, 'base' => $this->base,
            ]),
            $scales,
        ))];
    }

    public function inspectionPoint(array $scales): DatabasePoint
    {
        return (new GeneratorPlanner($this->base))->inspectionPoint($scales);
    }
}
