<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Run;

use PDO;

/**
 * A tap on the statements of ONE request (the cold attempt of `measure-one --tap-out=<file>`): for every statement the
 * app sends it notes how many rows the database read for it, and it keeps the totals by SQL text in a `StatementLedger`.
 * After the window is closed it asks the server for the plan of every text (EXPLAIN, with the first and the last values
 * the text was bound to), still on the same session and before the rollback.
 *
 * What a statement read is the growth of the session's seven `Handler_read_*` counters between the reading taken after
 * the statement before it and the one taken after this statement, less the cost of one reading (`RowsExamined`'s
 * baseline): the same definition as the figure of the attempt, and the same reading (`RowsExamined::read`). The readings
 * go to the app's PDO directly, so no `QueryExecuted` event fires for them and the query count stays the app's own.
 * They sit inside the window of the attempt's figure. That is harmless only while one reading costs nothing the figure
 * counts (the baseline is 0); when it does not, the figure of a tapped run is not the figure of an untapped one, and
 * `unaccounted` in the file shows it.
 *
 * The EXPLAINs are sent after the window is closed (after `RowsExamined::stop()`), so they are in no figure. Their
 * results go to the file only: never to the attempt, never to a field that a verdict reads.
 *
 * It is on only between `start()` and `stop()`; before and after, its listener does nothing. Without `--tap-out` it is
 * not created.
 */
final class StatementTap
{
    private const QUERY_EXECUTED = 'Illuminate\\Database\\Events\\QueryExecuted';

    private const ERROR_LIMIT = 160;

    private const EXPLAIN_COLUMNS = ['id', 'select_type', 'table', 'partitions', 'type', 'possible_keys', 'key', 'key_len', 'ref', 'rows', 'filtered', 'extra'];

    private bool $active = false;

    private StatementLedger $ledger;

    /** @var array<string, int>|null the counters as of the end of the last statement; null when they could not be read */
    private ?array $previous = null;

    /** @param int|null $baseline the reading cost taken off every statement; null: the counters cannot be used */
    public function __construct(
        private readonly ?PDO $pdo,
        private readonly ?int $baseline,
        private readonly string $defaultConnection,
    ) {
        $this->ledger = new StatementLedger();
    }

    public function countersAvailable(): bool
    {
        return $this->pdo !== null && $this->baseline !== null;
    }

    /** Subscribes to every connection's QueryExecuted through the app's event dispatcher; nothing is replaced. */
    public function listenTo(object $dispatcher): void
    {
        $dispatcher->listen(self::QUERY_EXECUTED, function (object $event): void {
            if ($this->active) {
                $this->onQuery((string) $event->sql, (array) $event->bindings, (string) ($event->connectionName ?? ''), $event->connection ?? null);
            }
        });
    }

    /** Opens the window. Call it just after `RowsExamined::start()`. */
    public function start(): void
    {
        $this->ledger = new StatementLedger();
        $this->previous = $this->countersAvailable() ? RowsExamined::read($this->pdo) : null;
        $this->active = true;
    }

    /** Closes the window. It reads nothing. */
    public function stop(): void
    {
        $this->active = false;
    }

    public function ledger(): StatementLedger
    {
        return $this->ledger;
    }

    /**
     * One statement has just been executed.
     *
     * @param array<array-key, mixed> $bindings
     */
    public function onQuery(string $sql, array $bindings, string $connectionName = '', ?object $connection = null): void
    {
        $rows = null;
        $onDefault = $connectionName === '' || $connectionName === $this->defaultConnection;
        if ($onDefault && $this->countersAvailable()) {
            $now = RowsExamined::read($this->pdo);
            if ($now !== null && $this->previous !== null) {
                $growth = RowsExamined::growth($this->previous, $now);
                // Kept as it is, even below zero: a clamp would hide a baseline that is too big.
                $rows = $growth === null ? null : $growth - (int) $this->baseline;
            }
            $this->previous = $now;
        }
        $this->ledger->record($sql, $rows, self::prepared($bindings, $connection));
    }

    /**
     * The plan of every text that can be explained, with its first and its last bindings. Call it after the window is
     * closed and before the rollback. Never throws: a plan that could not be taken is `{ok: false, error}`.
     *
     * @return array<string, array{first: ?array<string, mixed>, last: ?array<string, mixed>}> by SQL text
     */
    public function explainAll(): array
    {
        $explains = [];
        foreach ($this->ledger->statements() as $s) {
            if (!StatementLedger::canExplain($s['type'])) {
                continue;
            }
            $first = $this->explain($s['sql'], $s['first_bindings']);
            $last = $s['last_bindings'] === $s['first_bindings'] ? $first : $this->explain($s['sql'], $s['last_bindings']);
            $explains[$s['sql']] = ['first' => $first, 'last' => $last];
        }

        return $explains;
    }

    /**
     * @param list<mixed> $bindings
     *
     * @return array<string, mixed>
     */
    private function explain(string $sql, array $bindings): array
    {
        if ($this->pdo === null) {
            return ['ok' => false, 'error' => 'no connection'];
        }
        try {
            $statement = $this->pdo->prepare('EXPLAIN ' . $sql);
            foreach (array_values($bindings) as $i => $value) {
                $type = match (true) {
                    $value === null => PDO::PARAM_NULL,
                    is_int($value) => PDO::PARAM_INT,
                    default => PDO::PARAM_STR,
                };
                $statement->bindValue($i + 1, is_bool($value) ? (int) $value : $value, $type);
            }
            $statement->execute();

            return ['ok' => true, 'rows' => self::normalizeExplain($statement->fetchAll(PDO::FETCH_ASSOC))];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, self::ERROR_LIMIT)];
        }
    }

    /**
     * EXPLAIN's rows with the same columns in the same order, keyed in lower case (`Extra` is `extra`); the numbers the
     * server gives as text become numbers, everything else stays as it is.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    public static function normalizeExplain(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $lower = array_change_key_case($row, CASE_LOWER);
            $item = [];
            foreach (self::EXPLAIN_COLUMNS as $column) {
                $value = $lower[$column] ?? null;
                if ($value !== null && ($column === 'id' || $column === 'rows') && is_numeric($value)) {
                    $value = (int) $value;
                } elseif ($value !== null && $column === 'filtered' && is_numeric($value)) {
                    $value = (float) $value;
                }
                $item[$column] = $value;
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * What goes to the file for a finished window.
     *
     * @param array<string, mixed>                                                               $header
     * @param array<string, array{first: ?array<string, mixed>, last: ?array<string, mixed>}>    $explains
     *
     * @return array<string, mixed>
     */
    public function document(array $header, ?int $coldExamined, array $explains): array
    {
        return $this->ledger->document($header, $this->countersAvailable(), $this->baseline, $coldExamined, $explains);
    }

    /**
     * Writes the document as one line of JSON (a `.jsonl` file of one line). Returns a message when it could not be written (the caller says so on its error stream and
     * goes on: the measurement is the product, the tap is a record of it).
     *
     * @param array<string, mixed> $document
     */
    public static function write(string $path, array $document): ?string
    {
        try {
            $json = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION) . "\n";
        } catch (\Throwable $e) {
            return 'cannot encode the tap: ' . $e->getMessage();
        }
        if (@file_put_contents($path, $json, LOCK_EX) === false) {
            return 'cannot write the tap file';
        }

        return null;
    }

    /**
     * The bindings as Laravel sends them (a date becomes text, a boolean a number).
     *
     * @param array<array-key, mixed> $bindings
     *
     * @return list<mixed>
     */
    private static function prepared(array $bindings, ?object $connection): array
    {
        if ($connection !== null && method_exists($connection, 'prepareBindings')) {
            try {
                return array_values($connection->prepareBindings($bindings));
            } catch (\Throwable) {
                // fall through: the values as the app gave them
            }
        }

        return array_values($bindings);
    }
}
