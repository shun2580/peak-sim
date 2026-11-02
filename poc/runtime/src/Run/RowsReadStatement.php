<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Run;

use PDO;

/**
 * The statement class the measurement installs on the app's connection (`RowsRead::install`): it answers exactly as a
 * PDOStatement does and tells the ledger how many rows each reading gave and which tables the statement names. It
 * changes no result.
 */
final class RowsReadStatement extends \PDOStatement
{
    public static ?RowsRead $ledger = null;

    protected function __construct()
    {
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $row = parent::fetch($mode, $cursorOrientation, $cursorOffset);
        if ($row !== false) {
            self::$ledger?->add($this->queryString, 1);
        }

        return $row;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = parent::fetchAll($mode, ...$args);
        self::$ledger?->add($this->queryString, count($rows));

        return $rows;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $value = parent::fetchColumn($column);
        if ($value !== false) {
            self::$ledger?->add($this->queryString, 1);
        }

        return $value;
    }

    public function fetchObject(?string $class = 'stdClass', array $constructorArgs = []): object|false
    {
        $object = parent::fetchObject($class, $constructorArgs);
        if ($object !== false) {
            self::$ledger?->add($this->queryString, 1);
        }

        return $object;
    }
}
