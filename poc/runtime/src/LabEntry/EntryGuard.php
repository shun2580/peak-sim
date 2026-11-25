<?php

declare(strict_types=1);

namespace PeakSim\Runtime\LabEntry;

use PeakSim\Runtime\Run\QueryCounter;

/**
 * What the lab's entry script (poc/lab/app/entry.php) puts around every request now that a request is no longer
 * wrapped in a transaction that is rolled back (poc/lab/README.md, safety condition 6). The entry calls it for every
 * request, in this order: methodRefusal(), latchRefusal(), watch() before the app handles the request, finish() after.
 *
 *  - Only GET and HEAD are served (methodRefusal).
 *  - A latch file left by an earlier request makes every later request a 503 (latchRefusal).
 *  - Every statement is looked at on every connection BEFORE it is sent (watch: the connection's own before-execute
 *    hook). One that writes anything but `views` (or, for another app's lab, anything but the tables of its list: AllowedWrites), commits implicitly, writes on another connection, changes the server
 *    or writes a file is not sent: the latch is left and the statement raises LabWriteRefused. A statement on a
 *    connection that has no hook (one the app made in a way that skipped the connection event) is looked at after it
 *    ran, from the query event: the latch is left, but the statement has been sent.
 *  - After the request (finish), a statement that named another schema, a transaction left open, or any of the above
 *    seen late leaves the latch. The response has gone out already; what is left is to stop anything more.
 *
 * What it cannot see: a statement the app sends straight to PDO and not through Laravel's connection (BEGIN, COMMIT and
 * SAVEPOINT of the transaction calls are the known ones). The table counts and checksums taken before and after each
 * scenario are what cover that.
 */
final class EntryGuard
{
    private const FOREIGN = 'a statement referred to a schema other than the measurement database: ';

    private ?string $problem = null;

    /** @var \SplObjectStorage<object, true> the connections that already carry the hook */
    private \SplObjectStorage $hooked;

    public function __construct(
        private readonly string $latchPath,
        private readonly string $measureDb,
        private readonly string $defaultConnection,
        private readonly string $uri = '',
        /** @var list<string> the tables that may be written (AllowedWrites); `views` alone is the BookStack lab */
        private readonly array $allowedTables = AllowedWrites::DEFAULT,
    ) {
        $this->hooked = new \SplObjectStorage();
    }

    /** Why a request with this method is not served, or null. */
    public static function methodRefusal(string $method): ?string
    {
        return $method === 'GET' || $method === 'HEAD' ? null : 'only GET and HEAD are served';
    }

    /** The reason left in the latch file by an earlier request, or null when there is no latch. */
    public static function latchRefusal(string $latchPath): ?string
    {
        if (!is_file($latchPath)) {
            return null;
        }

        return trim((string) @file_get_contents($latchPath));
    }

    /**
     * Puts the statement watch on every connection of the app, those that exist now and those made later (also a
     * connection made again), and on the query event.
     */
    public function watch(object $db, object $events): void
    {
        foreach ($db->getConnections() as $connection) {
            $this->hook($connection);
        }
        $events->listen('Illuminate\\Database\\Events\\ConnectionEstablished', function (object $event): void {
            $this->hook($event->connection);
        });
        $events->listen('Illuminate\\Database\\Events\\QueryExecuted', function (object $event): void {
            if (!$this->hooked->contains($event->connection)) {
                $this->inspect((string) $event->connectionName, (string) $event->sql, false, is_array($event->bindings ?? null) ? $event->bindings : []);
            }
        });
    }

    /**
     * After the request has been handled and sent. Returns the reason the database can no longer be trusted (and
     * leaves the latch), or null.
     */
    public function finish(object $db, QueryCounter $counter): ?string
    {
        $problem = $this->problem;
        if ($problem === null && $counter->foreignSchemas() !== []) {
            $problem = self::FOREIGN . implode(', ', $counter->foreignSchemas());
        }
        if ($problem === null) {
            $problem = $counter->rollbackProblem();
        }
        $level = (int) $db->transactionLevel();
        if ($level > 0) {
            for ($i = 0; $i < 50 && $db->transactionLevel() > 0; $i++) {
                $db->rollBack();
            }
            $problem ??= 'transaction_left_open: the request left a transaction open';
        }
        if ($problem !== null) {
            $this->latch($problem);
        }

        return $problem;
    }

    private function hook(object $connection): void
    {
        if ($this->hooked->contains($connection)) {
            return;
        }
        $this->hooked->attach($connection, true);
        $connection->beforeExecuting(function (string $query, array $bindings, object $connection): void {
            $this->inspect((string) $connection->getName(), $query, true, $bindings);
        });
    }

    /** @param array<array-key, mixed> $bindings */
    private function inspect(string $connection, string $sql, bool $beforeRun, array $bindings = []): void
    {
        $reason = ViewsOnlyWrites::violation($connection, $this->defaultConnection, $this->measureDb, $sql, $this->allowedTables);
        if ($reason === null) {
            return;
        }
        $reason .= self::typeNote($sql, $bindings);
        $this->latch($reason);
        if ($beforeRun) {
            throw new LabWriteRefused('lab: refused before it was sent: ' . $reason);
        }
    }

    /**
     * What kind of record a refused insert was for, when the table keeps one in a column called `type` (a notification's type, a
     * log entry's kind): ` [type=system.app_url_mismatch]`, or an empty string. Only a value shaped like a name (lower-case letters, digits,
     * `_`, `.`, `-`, up to 64) is kept, because it names a kind of thing and is never data; anything else, and every other binding, stays out
     * of the latch and of the log. The statement's own text is the one the guard already keeps (placeholders, no values).
     *
     * @param array<array-key, mixed> $bindings
     */
    public static function typeNote(string $sql, array $bindings): string
    {
        if (preg_match('/^\s*insert\s+(?:ignore\s+)?into\s+`?[A-Za-z0-9_.]+`?\s*\(([^)]*)\)\s*values\s*\(([^)]*)\)/i', $sql, $m) !== 1) {
            return '';
        }
        $columns = array_map(static fn (string $c): string => strtolower(trim($c, " `\t\r\n")), explode(',', $m[1]));
        $values = array_map('trim', explode(',', $m[2]));
        // Only a row of plain placeholders, one for each column, lines the values up with the bindings by position.
        if (count($columns) !== count($values) || array_unique($values) !== ['?']) {
            return '';
        }
        $at = array_search('type', $columns, true);
        $bindings = array_values($bindings);
        if ($at === false || !isset($bindings[$at]) || !is_string($bindings[$at]) || preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $bindings[$at]) !== 1) {
            return '';
        }

        return ' [type=' . $bindings[$at] . ']';
    }

    private function latch(string $problem): void
    {
        if ($this->problem !== null) {
            return; // the first reason of the request is the one kept, and said once
        }
        $this->problem = $problem;
        if (!is_file($this->latchPath)) {
            @file_put_contents($this->latchPath, mb_substr($this->uri . ' ' . $problem, 0, 500) . "\n");
        }
        error_log(json_encode(['lab' => 'contaminated', 'uri' => $this->uri, 'problem' => mb_substr($problem, 0, 300)], JSON_UNESCAPED_SLASHES));
    }
}
