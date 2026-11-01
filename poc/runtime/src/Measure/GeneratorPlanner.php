<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

use PeakSim\Runtime\Route\RouteSpec;

/**
 * Data from the tool's generator (`Data\Generator` with a `Data\ScalePlan`), for any app.
 *
 * A route with a candidate table (n_table, plus the equally good alternatives, scaled together) gets a targeted plan:
 * that table at N rows, everything else at the base size, and N = 0 measured first as the empty system. A route with
 * none gets a global plan (every table N), which says how the cost grows with the whole database and nothing finer;
 * it has no empty point, because a global plan cannot hold zero rows. A route with an m_table also gets a second
 * sweep: the n table fixed at 20 rows, the children per parent row M = 3, 30, 300.
 */
final class GeneratorPlanner implements DataPlanner
{
    public const BASE = 5;

    public const M_FIXED_N = 20;

    /** The children per parent row while N is swept. */
    public const M_DURING_N = 3;

    /** @var list<int> */
    public const M_SCALES = [3, 30, 300];

    public function __construct(private readonly int $base = self::BASE)
    {
    }

    public function sweepsFor(RouteSpec $spec, array $scales): array
    {
        $nTables = [];
        foreach ([$spec->static['n_table'] ?? null, ...array_values((array) ($spec->static['n_table_alternatives'] ?? []))] as $table) {
            $bare = self::bare($table);
            if ($bare !== null) {
                $nTables[$bare] = true;
            }
        }
        $nTables = array_keys($nTables);
        sort($nTables, SORT_STRING);

        if ($nTables === []) {
            return [new Sweep('N', array_map(
                fn (int $n): DatabasePoint => new DatabasePoint('glob', $n, ['mode' => 'global', 'n' => $n, 'base' => $this->base]),
                $scales,
            ))];
        }

        $mTable = self::bare($spec->static['m_table'] ?? null);
        $mPer = self::bare($spec->static['m_per'] ?? null);
        $hasM = $mTable !== null && $mPer !== null && !in_array($mTable, $nTables, true);
        $shape = [$nTables, $hasM ? $mTable : null, $hasM ? $mPer : null];
        $nTag = 't' . substr(md5(json_encode($shape, JSON_THROW_ON_ERROR)), 0, 6);

        $points = [];
        foreach ([0, ...$scales] as $n) {
            $points[] = new DatabasePoint($nTag, $n, $this->targeted($nTables, $shape[1], $shape[2], $n, $hasM ? self::M_DURING_N : 0));
        }
        $sweeps = [new Sweep('N', $points)];

        if ($hasM) {
            $mTag = 'm' . substr(md5(json_encode($shape, JSON_THROW_ON_ERROR)), 0, 6);
            $sweeps[] = new Sweep('M', array_map(
                fn (int $m): DatabasePoint => new DatabasePoint($mTag, $m, $this->targeted($nTables, $mTable, $mPer, self::M_FIXED_N, $m)),
                self::M_SCALES,
            ));
        }

        return $sweeps;
    }

    public function inspectionPoint(array $scales): DatabasePoint
    {
        return new DatabasePoint('insp', $scales[0], ['mode' => 'global', 'n' => $scales[0], 'base' => $this->base]);
    }

    /**
     * @param list<string> $nTables
     *
     * @return array<string, mixed>
     */
    private function targeted(array $nTables, ?string $mTable, ?string $mPer, int $n, int $m): array
    {
        return ['mode' => 'targeted', 'n_tables' => $nTables, 'm_table' => $mTable, 'm_per' => $mPer, 'n' => $n, 'm' => $m, 'base' => $this->base];
    }

    private static function bare(mixed $table): ?string
    {
        if (!is_string($table) || $table === '') {
            return null;
        }
        $dot = strrpos($table, '.');

        return strtolower(trim($dot === false ? $table : substr($table, $dot + 1), '`"'));
    }
}
