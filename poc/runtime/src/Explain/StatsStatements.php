<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Explain;

use PeakSim\Runtime\Config\MeasureRefused;

/**
 * The statements the tool writes itself to get the optimizer's statistics into a known state, on a measurement database
 * it has just built: ANALYZE TABLE, and ALTER TABLE ... STATS_AUTO_RECALC / STATS_SAMPLE_PAGES. This is the second door;
 * `ExplainGate`/`ExplainSender` is the first, and the two never meet.
 *
 *  - There is no method that takes a statement. Each method builds its own text from a fixed pattern and from table names.
 *  - A table name is accepted only if the database itself listed it (`tables()`), and only if it is plain
 *    (`[A-Za-z0-9_]{1,64}`). A name that came from a capture file is therefore a hint for choosing among the database's
 *    tables, never text that is sent.
 *  - The number of sample pages is one of `SAMPLE_PAGES`, never a free value.
 *
 * The `measure` user holds ALL on the measurement databases only (`compose/mysql-init/10-measure-user.sql`), which
 * covers these statements; nothing is added to its privileges.
 */
final class StatsStatements
{
    /** The statistics states and the number of pages ANALYZE TABLE samples in each (the server's default is 20). */
    public const SAMPLE_PAGES = ['default' => 20, 'low' => 4, 'high' => 200];

    /** @var array<string, true>|null */
    private ?array $known = null;

    public function __construct(private readonly StatsDb $db)
    {
    }

    /**
     * The base tables of the database the connection is on.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        $rows = $this->db->run("SELECT table_name AS t FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name");
        $names = [];
        foreach ($rows as $row) {
            $name = (string) ($row['t'] ?? $row['T'] ?? $row['TABLE_NAME'] ?? reset($row));
            if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $name) === 1) {
                $names[] = $name;
            }
        }
        $this->known = array_fill_keys($names, true);

        return $names;
    }

    /**
     * The tables of the database that a set of hinted names points at (a name that is not a table here is dropped).
     *
     * @param list<string> $hints
     *
     * @return list<string>
     */
    public function relevant(array $hints): array
    {
        $known = $this->known ?? array_fill_keys($this->tables(), true);
        $out = [];
        foreach (array_unique($hints) as $hint) {
            if (isset($known[$hint])) {
                $out[] = $hint;
            }
        }
        sort($out);

        return $out;
    }

    /**
     * What the optimizer's statistics say each base table holds (`table_rows` of information_schema, read with the statistics cache
     * switched off for this connection), by table. Read before and after ANALYZE TABLE it shows what the statement changed.
     *
     * @return array<string, int>
     */
    public function tableRows(): array
    {
        $this->db->run('SET SESSION information_schema_stats_expiry = 0');
        $rows = $this->db->run("SELECT table_name AS t, table_rows AS n FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name");
        $out = [];
        foreach ($rows as $row) {
            $name = (string) ($row['t'] ?? $row['T'] ?? $row['TABLE_NAME'] ?? reset($row));
            if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $name) === 1) {
                $out[$name] = (int) ($row['n'] ?? $row['N'] ?? $row['TABLE_ROWS'] ?? 0);
            }
        }

        return $out;
    }

    /**
     * ANALYZE TABLE on each table. Returns, per table, the server's message rows (`Msg_type`, `Msg_text`).
     *
     * @param list<string> $tables
     *
     * @return array<string, list<array<string, mixed>>>
     *
     * @throws MeasureRefused
     */
    public function analyze(array $tables): array
    {
        $result = [];
        foreach ($tables as $table) {
            $result[$table] = $this->db->run('ANALYZE TABLE ' . $this->identifier($table));
        }

        return $result;
    }

    /**
     * @param list<string> $tables
     *
     * @throws MeasureRefused
     */
    public function switchAutoRecalcOff(array $tables): void
    {
        foreach ($tables as $table) {
            $this->db->run('ALTER TABLE ' . $this->identifier($table) . ' STATS_AUTO_RECALC=0');
        }
    }

    /**
     * Sets how many pages ANALYZE TABLE samples on each table (it does not run ANALYZE).
     *
     * @param list<string> $tables
     *
     * @throws MeasureRefused
     */
    public function setSamplePages(array $tables, int $pages): void
    {
        if (!in_array($pages, self::SAMPLE_PAGES, true)) {
            throw new MeasureRefused(sprintf('refused: %d is not one of the sample page counts of the states (%s)', $pages, implode(', ', self::SAMPLE_PAGES)));
        }
        foreach ($tables as $table) {
            $this->db->run('ALTER TABLE ' . $this->identifier($table) . ' STATS_SAMPLE_PAGES=' . $pages);
        }
    }

    /**
     * The server's own statistics settings, to be written down with the record.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $rows = $this->db->run('SELECT @@innodb_stats_persistent AS persistent, @@innodb_stats_persistent_sample_pages AS sample_pages, @@innodb_stats_auto_recalc AS auto_recalc, @@version AS version');

        return $rows[0] ?? [];
    }

    /** @throws MeasureRefused */
    private function identifier(string $table): string
    {
        if ($this->known === null) {
            $this->tables();
        }
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table) !== 1 || !isset($this->known[$table])) {
            throw new MeasureRefused(sprintf('refused: %s is not a table of the measurement database', json_encode(substr($table, 0, 64), JSON_INVALID_UTF8_SUBSTITUTE) ?: '""'));
        }

        return '`' . $table . '`';
    }
}
