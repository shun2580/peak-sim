<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Run;

use PDO;

/**
 * How many rows each table gave to the app while one request ran: the rows the statements returned, credited to each
 * table the statement names. It is not the rows the database looked at and not the size of a table; it is what reached
 * the app, which is what decides whether a page has anything in it. It sits on the app's own connection by the
 * statement class (`RowsReadStatement`), so the app's queries are the ones counted, and it counts nothing outside
 * `start()` and `stop()`.
 *
 * When a connection cannot take the statement class (a persistent one), `available()` is false and `rows()` is null:
 * no figure is better than a wrong one.
 */
final class RowsRead
{
    private bool $active = false;

    /** @var array<string, int> */
    private array $rows = [];

    private function __construct(private readonly bool $available)
    {
    }

    public static function install(PDO $pdo): self
    {
        $ledger = new self(true);
        try {
            if ($pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RowsReadStatement::class, []]) !== true) {
                return self::unavailable();
            }
        } catch (\Throwable) {
            return self::unavailable();
        }
        RowsReadStatement::$ledger = $ledger;

        return $ledger;
    }

    public static function unavailable(): self
    {
        return new self(false);
    }

    public function available(): bool
    {
        return $this->available;
    }

    public function start(): void
    {
        $this->rows = [];
        $this->active = true;
    }

    public function stop(): void
    {
        $this->active = false;
    }

    public function add(string $sql, int $rows): void
    {
        if (!$this->active || $rows < 1) {
            return;
        }
        foreach (QueryCounter::tablesIn($sql) as $table) {
            $this->rows[$table] = ($this->rows[$table] ?? 0) + $rows;
        }
    }

    /** @return array<string, int>|null table => rows read, sorted by table; null when the connection cannot be counted */
    public function rows(): ?array
    {
        if (!$this->available) {
            return null;
        }
        ksort($this->rows, SORT_STRING);

        return $this->rows;
    }
}
