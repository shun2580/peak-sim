<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

use PeakSim\Runtime\Explain\StatsStatements;

/**
 * A database builder that, right after the database is built and before any statement of a route is sent, puts the optimizer's
 * statistics in the state the EXPLAIN step puts its databases in: ANALYZE TABLE on every table, then STATS_AUTO_RECALC=0 on them, so
 * that the plans a capture sees are the plans EXPLAIN reads (a database that has just been filled keeps statistics from before the fill,
 * and the optimizer can then pick a plan that the analysed database does not).
 *
 * Set only by `bin/capture --analyze-after-build=on` (the option is off by default and `bin/measure` never passes it). The statements are
 * the two fixed patterns of {@see StatsStatements}, sent as the `measure` user on the one database that was just built.
 */
final class AnalyzingDatabaseBuilder implements DatabaseBuilder
{
    /** @param \Closure(DatabasePoint): StatsStatements $statsFor the statements on the database of that point */
    public function __construct(
        private readonly DatabaseBuilder $inner,
        private readonly \Closure $statsFor,
    ) {
    }

    /**
     * @throws BuildFailure when ANALYZE TABLE reports an error on a table
     */
    public function build(DatabasePoint $point): array
    {
        $built = $this->inner->build($point);
        $stats = ($this->statsFor)($point);
        $tables = $stats->tables();
        $before = $stats->tableRows();
        $analyzed = $stats->analyze($tables);
        $errors = [];
        foreach ($analyzed as $table => $messages) {
            foreach ($messages as $m) {
                if (strtolower((string) ($m['Msg_type'] ?? '')) === 'error') {
                    $errors[] = $table . ': ' . (string) ($m['Msg_text'] ?? '');
                }
            }
        }
        if ($errors !== []) {
            throw new BuildFailure('ANALYZE TABLE reported an error: ' . implode('; ', array_slice($errors, 0, 3)));
        }
        $stats->switchAutoRecalcOff($tables);
        $after = $stats->tableRows();
        // what the statistics said each table holds before and after ANALYZE TABLE, for the tables where it changed (the `mysql` schema,
        // with `last_update`, is not readable by the `measure` user and its privileges are not widened for this)
        $changed = [];
        foreach ($after as $table => $rows) {
            if (($before[$table] ?? null) !== $rows) {
                $changed[$table] = ['before' => $before[$table] ?? null, 'after' => $rows];
            }
        }

        return $built + ['statistics' => ['analyzed_tables' => count($tables), 'auto_recalc_off_on' => count($tables), 'table_rows_changed' => $changed, 'server' => $stats->settings()]];
    }

    public function drop(DatabasePoint $point): void
    {
        $this->inner->drop($point);
    }
}
