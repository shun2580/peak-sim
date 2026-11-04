<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Guard;

use PDO;
use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Config\MeasureRefused;

/**
 * Stops the statements a measurement database is still running after the client that sent them was killed.
 *
 * Killing a child process closes its socket, but a statement the server has already started (an INSERT ... SELECT, a
 * long SELECT) runs on until it ends, holds its metadata lock, and makes the DROP DATABASE that follows wait for it.
 * So the tool, from its own connection, sends `KILL QUERY` to the connections that are on the measurement database.
 *
 * It uses the `measure` user and no extra privilege: that user sees (SHOW PROCESSLIST) and kills only the threads of the
 * same user, which is all it needs. Only threads whose current database is the named measurement database are touched.
 */
final class StatementKiller
{
    /** The longest statement text kept in a record. */
    public const STATEMENT_LIMIT = 300;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Connects server-wide as the `measure` user (the same door as MeasureDb). */
    public static function connect(MeasureConfig $config): self
    {
        DbGuard::assertHost($config->dbHost);
        DbGuard::assertUser($config->dbUser);
        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $config->dbHost, $config->dbPort);

        return new self(new PDO($dsn, $config->dbUser, $config->dbPassword, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]));
    }

    /**
     * Sends KILL QUERY to every other connection that is on the measurement database, and waits (up to $waitSeconds) for
     * the statements to end. Returns what was running, for the record.
     *
     * @return array{killed: list<array{id: int, seconds: int, statement: string, table: string|null}>, still_running: int}
     *
     * @throws MeasureRefused
     */
    public function killOn(string $database, int $waitSeconds = 20): array
    {
        DbGuard::assertDbName($database);
        $own = (int) $this->pdo->query('SELECT CONNECTION_ID()')->fetchColumn();

        $killed = [];
        foreach ($this->threadsOn($database, $own) as $thread) {
            if ($thread['command'] === 'Query') {
                $killed[] = [
                    'id' => $thread['id'],
                    'seconds' => $thread['seconds'],
                    'statement' => mb_substr($thread['statement'], 0, self::STATEMENT_LIMIT),
                    'table' => self::tableOf($thread['statement']),
                ];
            }
            try {
                $this->pdo->exec('KILL QUERY ' . $thread['id']);
            } catch (\PDOException) {
                // The thread ended between the list and the kill: that is what was wanted.
            }
        }

        $deadline = microtime(true) + $waitSeconds;
        do {
            $running = array_filter($this->threadsOn($database, $own), static fn (array $t): bool => $t['command'] === 'Query');
            if ($running === []) {
                break;
            }
            usleep(200_000);
        } while (microtime(true) < $deadline);

        return ['killed' => $killed, 'still_running' => count($running)];
    }

    /**
     * The table a statement writes to or reads from first, for the record; null when it cannot be told.
     */
    public static function tableOf(string $statement): ?string
    {
        if (preg_match('/^\s*(?:insert\s+(?:ignore\s+)?into|replace\s+into|update|delete\s+from)\s+`?([A-Za-z0-9_$]+(?:`?\.`?[A-Za-z0-9_$]+)?)`?/i', $statement, $m) === 1
            || preg_match('/\bfrom\s+`?([A-Za-z0-9_$]+(?:`?\.`?[A-Za-z0-9_$]+)?)`?/i', $statement, $m) === 1) {
            return str_replace('`', '', $m[1]);
        }

        return null;
    }

    /** @return list<array{id: int, command: string, seconds: int, statement: string}> */
    private function threadsOn(string $database, int $own): array
    {
        $threads = [];
        foreach ($this->pdo->query('SHOW FULL PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            if ((int) $row['id'] === $own || ($row['db'] ?? null) !== $database) {
                continue;
            }
            $threads[] = [
                'id' => (int) $row['id'],
                'command' => (string) ($row['command'] ?? ''),
                'seconds' => (int) ($row['time'] ?? 0),
                'statement' => (string) ($row['info'] ?? ''),
            ];
        }

        return $threads;
    }
}
