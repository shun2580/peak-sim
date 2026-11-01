<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Run;

/**
 * Counts the SQL statements of one attempt, on every connection, and notes what the statements say about
 * rollback and schema. It does not know Laravel: an adapter feeds it (see listenTo()).
 *
 * Statements sent through PDO's own transaction calls (BEGIN, COMMIT, SAVEPOINT) never reach the event, so they are
 * not counted. Statements issued by the tool itself must go through a separate connection, not this one.
 */
final class QueryCounter
{
    /** The statements that end a transaction by themselves in MySQL, so a rollback afterwards undoes nothing. */
    private const IMPLICIT_COMMIT = '/^\s*(?:ALTER|CREATE|DROP|TRUNCATE|RENAME|LOCK\s+TABLES|UNLOCK\s+TABLES|SET\s+autocommit|START\s+TRANSACTION|BEGIN|COMMIT|GRANT|REVOKE|FLUSH|ANALYZE|OPTIMIZE|REPAIR|LOAD\s+DATA|CALL)\b/i';

    private const WRITE = '/^\s*(?:INSERT|UPDATE|DELETE|REPLACE)\b/i';

    /** schema.table or table after a keyword that introduces a table. `for update` and `on duplicate key update` are not tables. */
    private const TABLE_REF = '/(?<!for )(?<!key )\b(?:from|join|into|update)\s+(?:`?([A-Za-z0-9_$]+)`?\s*\.\s*)?`?([A-Za-z0-9_$]+)`?/i';

    private const USE = '/^\s*use\s+`?([A-Za-z0-9_$]+)`?/i';

    private const ALLOWED_SCHEMAS = ['information_schema'];

    private const STATEMENT_KEEP = 200;

    private bool $active = false;

    private int $total = 0;

    /** @var array<string, int> */
    private array $byConnection = [];

    /** @var array<string, true> */
    private array $tables = [];

    /** @var list<string> */
    private array $implicitCommits = [];

    /** @var array<string, true> */
    private array $foreignSchemas = [];

    /** @var array<string, true> */
    private array $otherConnectionWrites = [];

    public function __construct(
        private readonly string $measureDb,
        private readonly string $defaultConnection,
    ) {
    }

    public function start(): void
    {
        $this->total = 0;
        $this->byConnection = [];
        $this->tables = [];
        $this->implicitCommits = [];
        $this->foreignSchemas = [];
        $this->otherConnectionWrites = [];
        $this->active = true;
    }

    public function stop(): void
    {
        $this->active = false;
    }

    /**
     * Subscribes to every connection's QueryExecuted through the application's event dispatcher. Nothing is
     * replaced: the app's own listeners keep running.
     */
    public function listenTo(object $dispatcher): void
    {
        $dispatcher->listen('Illuminate\\Database\\Events\\QueryExecuted', function (object $event): void {
            $this->record((string) $event->connectionName, (string) $event->sql);
        });
    }

    public function record(string $connection, string $sql): void
    {
        if (!$this->active) {
            return;
        }
        $this->total++;
        $this->byConnection[$connection] = ($this->byConnection[$connection] ?? 0) + 1;

        foreach (self::references($sql) as [$schema, $table]) {
            $this->tables[$table] = true;
            if ($schema !== null && !$this->isAllowedSchema($schema)) {
                $this->foreignSchemas[$schema] = true;
            }
        }
        if (preg_match(self::USE, $sql, $m) === 1 && !$this->isAllowedSchema($m[1])) {
            $this->foreignSchemas[$m[1]] = true;
        }
        if (self::isImplicitCommit($sql)) {
            $this->implicitCommits[] = mb_substr($sql, 0, self::STATEMENT_KEEP);
        }
        if ($connection !== $this->defaultConnection && self::isWrite($sql)) {
            $this->otherConnectionWrites[$connection] = true;
        }
    }

    public function total(): int
    {
        return $this->total;
    }

    /** @return array<string, int> */
    public function byConnection(): array
    {
        return $this->byConnection;
    }

    /** @return list<string> sorted, no duplicates */
    public function tables(): array
    {
        $names = array_keys($this->tables);
        sort($names, SORT_STRING);

        return $names;
    }

    /** @return list<string> */
    public function implicitCommits(): array
    {
        return $this->implicitCommits;
    }

    /** @return list<string> sorted */
    public function foreignSchemas(): array
    {
        $names = array_keys($this->foreignSchemas);
        sort($names, SORT_STRING);

        return $names;
    }

    /** The reason this attempt cannot be rolled back, or null. Feeds Classification::decide(). */
    public function rollbackProblem(): ?string
    {
        if ($this->implicitCommits !== []) {
            return 'ddl: ' . $this->implicitCommits[0];
        }
        if ($this->otherConnectionWrites !== []) {
            return 'other_connection: ' . array_key_first($this->otherConnectionWrites);
        }

        return null;
    }

    /** @return list<string> table names in the order of first sight */
    public static function tablesIn(string $sql): array
    {
        $names = [];
        foreach (self::references($sql) as [, $table]) {
            if (!in_array($table, $names, true)) {
                $names[] = $table;
            }
        }

        return $names;
    }

    public static function isImplicitCommit(string $sql): bool
    {
        return preg_match(self::IMPLICIT_COMMIT, $sql) === 1;
    }

    public static function isWrite(string $sql): bool
    {
        return preg_match(self::WRITE, $sql) === 1;
    }

    /** @return list<array{?string, string}> [schema or null, table] */
    private static function references(string $sql): array
    {
        $sql = (string) preg_replace('/\s+/', ' ', $sql);
        if (preg_match_all(self::TABLE_REF, $sql, $matches, PREG_SET_ORDER) < 1) {
            return [];
        }
        $found = [];
        foreach ($matches as $m) {
            $found[] = [($m[1] ?? '') === '' ? null : $m[1], $m[2]];
        }

        return $found;
    }

    private function isAllowedSchema(string $schema): bool
    {
        return $schema === $this->measureDb || in_array(strtolower($schema), self::ALLOWED_SCHEMAS, true);
    }
}
