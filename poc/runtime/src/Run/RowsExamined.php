<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Run;

use PDO;

/**
 * How many rows the database looked at while one request ran ("rows examined"): the growth of the session's
 * `Handler_read_*` counters between a snapshot taken before the request and one taken after it. It is not the rows
 * the statements returned (that is `RowsRead`); a query that scans a table and returns one row examined the whole table.
 *
 * The snapshots are `SHOW SESSION STATUS LIKE 'Handler_read%'`, sent on the app's own PDO, so they see the app's
 * session and nobody else's. They go to the PDO directly, not through Laravel's connection: no `QueryExecuted`
 * event fires (the query count stays the app's own), and `start()`/`stop()` are called outside the window of
 * `RowsRead` (before its `start()`, after its `stop()`), so its statement class does not count them either.
 *
 * Asking for the counters is itself a statement that reads a little. That cost is measured once, when the ledger is
 * installed (two snapshots in a row, nothing in between), kept as `baseline()` and taken off every result.
 *
 * Nothing is better than a wrong number: when the counters cannot be read (no permission, a persistent connection
 * shared by other sessions, the session reset by a reconnect), `rows()` is null and `baseline()` may be too. A null is
 * never turned into 0.
 */
final class RowsExamined
{
    /** The counters that add up to "rows examined". */
    public const COUNTERS = [
        'Handler_read_first',
        'Handler_read_key',
        'Handler_read_last',
        'Handler_read_next',
        'Handler_read_prev',
        'Handler_read_rnd',
        'Handler_read_rnd_next',
    ];

    private const SQL = "SHOW SESSION STATUS LIKE 'Handler_read%'";

    /** @var array<string, int>|null */
    private ?array $before = null;

    private ?int $rows = null;

    /**
     * @param int|null $baseline the growth caused by one pair of snapshots; null when it could not be measured
     */
    private function __construct(
        private readonly ?PDO $pdo,
        private readonly ?int $baseline,
    ) {
    }

    /**
     * Installs the ledger on the app's default connection and measures the baseline. Never throws: a connection that
     * cannot be read gives `unavailable()`.
     */
    public static function install(PDO $pdo): self
    {
        try {
            if ($pdo->getAttribute(PDO::ATTR_PERSISTENT)) {
                return self::unavailable();
            }
        } catch (\Throwable) {
            // a driver that cannot tell is treated as not persistent; the snapshots below decide
        }

        // The first statement of a session may read more than the ones after it (the status tables warm up): discard it.
        self::read($pdo);
        $a = self::read($pdo);
        $b = self::read($pdo);
        if ($a === null || $b === null) {
            return self::unavailable();
        }
        $growth = self::growth($a, $b);

        return $growth === null ? self::unavailable() : new self($pdo, $growth);
    }

    public static function unavailable(): self
    {
        return new self(null, null);
    }

    public function available(): bool
    {
        return $this->pdo !== null;
    }

    /** The reading cost of one pair of snapshots, taken off every result; null when unavailable. */
    public function baseline(): ?int
    {
        return $this->baseline;
    }

    /** Takes the snapshot before the request. Call it just before `RowsRead::start()`. */
    public function start(): void
    {
        $this->rows = null;
        $this->before = $this->pdo === null ? null : self::read($this->pdo);
    }

    /** Takes the snapshot after the request and keeps the result. Call it just after `RowsRead::stop()`. */
    public function stop(): void
    {
        $before = $this->before;
        $this->before = null;
        $this->rows = null;
        if ($this->pdo === null || $before === null || $this->baseline === null) {
            return;
        }
        $after = self::read($this->pdo);
        if ($after === null) {
            return;
        }
        $growth = self::growth($before, $after);
        // Below the cost of the snapshots themselves is noise, not a negative number of rows.
        $this->rows = $growth === null ? null : max(0, $growth - $this->baseline);
    }

    /** The rows examined in the last start()/stop() window, without the baseline; null when it could not be read. */
    public function rows(): ?int
    {
        return $this->rows;
    }

    /**
     * The sum of the seven counters' growth, or null when any counter went down or is missing (the session was
     * replaced, so the two snapshots are not of the same session).
     *
     * @param array<string, int> $before
     * @param array<string, int> $after
     */
    public static function growth(array $before, array $after): ?int
    {
        $sum = 0;
        foreach (self::COUNTERS as $name) {
            if (!isset($before[$name], $after[$name])) {
                return null;
            }
            $delta = $after[$name] - $before[$name];
            if ($delta < 0) {
                return null;
            }
            $sum += $delta;
        }

        return $sum;
    }

    /**
     * @return array<string, int>|null the Handler_read_* counters of this session, or null when they cannot be read
     *                                 (public so that `StatementTap` reads them in exactly this way)
     */
    public static function read(PDO $pdo): ?array
    {
        try {
            $statement = $pdo->query(self::SQL);
            if ($statement === false) {
                return null;
            }
            $values = [];
            foreach ($statement->fetchAll(PDO::FETCH_NUM) as $row) {
                if (is_array($row) && isset($row[0], $row[1]) && in_array((string) $row[0], self::COUNTERS, true) && is_numeric($row[1])) {
                    $values[(string) $row[0]] = (int) $row[1];
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return count($values) === count(self::COUNTERS) ? $values : null;
    }
}
