<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Data;

use PDO;
use PDOException;

/** Writes to MySQL through PDO and sorts the server's refusals into Reason codes. */
final class PdoSink implements RowSink
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function currentDatabase(): ?string
    {
        $name = $this->pdo->query('SELECT DATABASE()')->fetchColumn();

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function begin(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function insert(string $table, array $columns, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $names = implode(', ', array_map(Sql::identifier(...), $columns));
        $group = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $sql = sprintf('INSERT INTO %s (%s) VALUES %s', Sql::identifier($table), $names, implode(', ', array_fill(0, count($rows), $group)));
        $flat = [];
        foreach ($rows as $row) {
            foreach ($row as $value) {
                $flat[] = $value;
            }
        }
        try {
            $this->pdo->prepare($sql)->execute($flat);
        } catch (PDOException $e) {
            throw self::failure($e);
        }
    }

    public function execute(string $sql): int
    {
        try {
            return (int) $this->pdo->exec($sql);
        } catch (PDOException $e) {
            throw self::failure($e);
        }
    }

    public function count(string $table): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . Sql::identifier($table))->fetchColumn();
    }

    public function span(string $table, string $column): ?array
    {
        $row = $this->pdo->query(sprintf('SELECT MIN(%1$s), MAX(%1$s) FROM %2$s', Sql::identifier($column), Sql::identifier($table)))->fetch(PDO::FETCH_NUM);
        if ($row === false || $row[0] === null) {
            return null;
        }

        return [(int) $row[0], (int) $row[1]];
    }

    public static function failure(PDOException $e): SinkFailure
    {
        $code = (int) ($e->errorInfo[1] ?? 0);

        return new SinkFailure(self::reasonForMysqlCode($code), $e->getMessage());
    }

    /** The MySQL error number (errorInfo[1]) as a Reason code. */
    public static function reasonForMysqlCode(int $code): string
    {
        return match ($code) {
            3819 => Reason::CHECK_CONSTRAINT,
            1062, 1586 => Reason::UNIQUE_VIOLATION,
            1452, 1216 => Reason::FK_VIOLATION,
            1048 => Reason::NOT_NULL_VIOLATION,
            1364 => Reason::NO_DEFAULT,
            1406 => Reason::DATA_TOO_LONG,
            1264 => Reason::OUT_OF_RANGE,
            default => Reason::INSERT_ERROR,
        };
    }
}
