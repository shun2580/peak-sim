<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Run;

/**
 * Adds up what the statements of one request read, grouped by the SQL text (the text with its `?` placeholders, so a
 * statement the app sends 100,000 times with other values is one entry). For every text it keeps how many times it ran,
 * the rows read in total and at the least and the most for one run, and the first and the last values it was bound to
 * (so a plan can be asked for with each).
 *
 * It knows nothing of the database or of Laravel: `StatementTap` feeds it. A run whose rows could not be read is counted
 * (`unread`) and kept out of the sums; a null is never turned into 0.
 */
final class StatementLedger
{
    /** @var array<string, array{count: int, rowsSum: int, rowsMin: ?int, rowsMax: ?int, unread: int, firstBindings: list<mixed>, lastBindings: list<mixed>}> */
    private array $entries = [];

    private int $events = 0;

    private int $unread = 0;

    private int $rowsSum = 0;

    /**
     * @param int|null    $rows     rows the database read for this run; null when it could not be told
     * @param list<mixed> $bindings the values the statement was sent with
     */
    public function record(string $sql, ?int $rows, array $bindings): void
    {
        $this->events++;
        $entry = $this->entries[$sql] ?? ['count' => 0, 'rowsSum' => 0, 'rowsMin' => null, 'rowsMax' => null, 'unread' => 0, 'firstBindings' => $bindings, 'lastBindings' => $bindings];
        $entry['count']++;
        $entry['lastBindings'] = $bindings;
        if ($rows === null) {
            $entry['unread']++;
            $this->unread++;
        } else {
            $entry['rowsSum'] += $rows;
            $entry['rowsMin'] = $entry['rowsMin'] === null ? $rows : min($entry['rowsMin'], $rows);
            $entry['rowsMax'] = $entry['rowsMax'] === null ? $rows : max($entry['rowsMax'], $rows);
            $this->rowsSum += $rows;
        }
        $this->entries[$sql] = $entry;
    }

    /** How many statements were recorded. */
    public function events(): int
    {
        return $this->events;
    }

    /** How many of them had no readable rows. */
    public function unread(): int
    {
        return $this->unread;
    }

    /** The rows of the statements that could be read, added up. */
    public function rowsSum(): int
    {
        return $this->rowsSum;
    }

    /**
     * The statements in the order they first appeared.
     *
     * @return list<array{sql: string, type: string, count: int, rows_sum: int, rows_min: ?int, rows_max: ?int, unread: int, first_bindings: list<mixed>, last_bindings: list<mixed>}>
     */
    public function statements(): array
    {
        $list = [];
        foreach ($this->entries as $sql => $e) {
            $list[] = [
                'sql' => (string) $sql,
                'type' => self::typeOf((string) $sql),
                'count' => $e['count'],
                'rows_sum' => $e['rowsSum'],
                'rows_min' => $e['rowsMin'],
                'rows_max' => $e['rowsMax'],
                'unread' => $e['unread'],
                'first_bindings' => $e['firstBindings'],
                'last_bindings' => $e['lastBindings'],
            ];
        }

        return $list;
    }

    /** The first word of the statement, in lower case (`select`, `update`, `delete`, `insert`, ...); `''` when there is none. */
    public static function typeOf(string $sql): string
    {
        return preg_match('/^\s*(?:\(\s*)*([A-Za-z]+)/', $sql, $m) === 1 ? strtolower($m[1]) : '';
    }

    /** Whether the server can be asked for the plan of this kind of statement (`EXPLAIN` takes these). */
    public static function canExplain(string $type): bool
    {
        return in_array($type, ['select', 'with', 'update', 'delete', 'insert', 'replace'], true);
    }

    /** A bound value as it is written to the file: a long string is cut, anything that is not a scalar becomes text. */
    public static function showBinding(mixed $value, int $limit = 200): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_string($value)) {
            $length = mb_strlen($value, 'UTF-8');

            return $length <= $limit ? $value : mb_substr($value, 0, $limit, 'UTF-8') . '…(+' . ($length - $limit) . ')';
        }

        return is_object($value) ? '[' . get_class($value) . ']' : '[' . gettype($value) . ']';
    }

    /**
     * The whole record of one window, as the file `--tap-out` gets it.
     *
     * @param array<string, mixed>                                $header         route, method, uri, scale, axis, status, class
     * @param array<string, array{first: mixed, last: mixed}>     $explains       per SQL text, the plans taken after the window
     * @param int|null                                            $coldExamined   the attempt's rows_examined (baseline taken off); null when not measured
     *
     * @return array<string, mixed>
     */
    public function document(array $header, bool $countersAvailable, ?int $baseline, ?int $coldExamined, array $explains): array
    {
        $statements = [];
        foreach ($this->statements() as $s) {
            $explain = $explains[$s['sql']] ?? null;
            $statements[] = [
                'sql' => \PeakSim\Runtime\Capture\SecretBindings::redactSql($s['sql']),
                'type' => $s['type'],
                'count' => $s['count'],
                'rows_sum' => $countersAvailable ? $s['rows_sum'] : null,
                'rows_min' => $countersAvailable ? $s['rows_min'] : null,
                'rows_max' => $countersAvailable ? $s['rows_max'] : null,
                'unread' => $s['unread'],
                // a value bound to a secret column never reaches the file (Capture\SecretBindings)
                'first_bindings' => array_map(static fn (mixed $v): mixed => self::showBinding($v), \PeakSim\Runtime\Capture\SecretBindings::redactBindings($s['sql'], $s['first_bindings'])),
                'last_bindings' => array_map(static fn (mixed $v): mixed => self::showBinding($v), \PeakSim\Runtime\Capture\SecretBindings::redactBindings($s['sql'], $s['last_bindings'])),
                'explain_first' => $explain['first'] ?? null,
                'explain_last' => $explain['last'] ?? null,
            ];
        }
        $sum = $countersAvailable ? $this->rowsSum : null;

        return ['tap_version' => 1] + $header + [
            'phase' => 'cold',
            'window' => [
                'counters_available' => $countersAvailable,
                'baseline' => $baseline,
                'cold_rows_examined' => $coldExamined,
                'statement_events' => $this->events,
                'rows_sum' => $sum,
                'unaccounted' => $sum === null || $coldExamined === null ? null : $coldExamined - $sum,
                'unread_events' => $this->unread,
            ],
            'statements' => $statements,
        ];
    }
}
