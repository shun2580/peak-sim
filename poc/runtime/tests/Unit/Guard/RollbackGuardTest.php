<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Guard;

use PeakSim\Runtime\Guard\RollbackGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RollbackGuardTest extends TestCase
{
    /** @return array<string, array{0: string, 1: ?string}> */
    public static function statements(): array
    {
        return [
            // implicit commit
            'alter table' => ['ALTER TABLE users ADD COLUMN x int', 'ddl'],
            'create table' => ['create table t (id int)', 'ddl'],
            'create index' => ['CREATE INDEX i ON t (a)', 'ddl'],
            'drop table' => ['DROP TABLE t', 'ddl'],
            'drop database' => ['DROP DATABASE other', 'ddl'],
            'truncate' => ['TRUNCATE TABLE t', 'ddl'],
            'truncate without TABLE' => ['truncate t', 'ddl'],
            'rename' => ['RENAME TABLE a TO b', 'ddl'],
            'lock tables' => ['LOCK TABLES t WRITE', 'lock'],
            'unlock tables' => ['UNLOCK TABLES', 'lock'],
            'set autocommit' => ['SET autocommit = 1', 'transaction_control'],
            'set session autocommit' => ['SET SESSION autocommit=0', 'transaction_control'],
            'set at-at autocommit' => ['SET @@autocommit = 1', 'transaction_control'],
            'start transaction' => ['START TRANSACTION', 'transaction_control'],
            'begin' => ['BEGIN', 'transaction_control'],
            'commit' => ['COMMIT', 'transaction_control'],
            'grant' => ['GRANT ALL ON x.* TO y', 'privilege'],
            'revoke' => ['REVOKE ALL ON x.* FROM y', 'privilege'],
            'flush' => ['FLUSH PRIVILEGES', 'privilege'],
            'analyze' => ['ANALYZE TABLE t', 'maintenance'],
            'optimize' => ['OPTIMIZE TABLE t', 'maintenance'],
            'repair' => ['REPAIR TABLE t', 'maintenance'],
            'load data' => ["LOAD DATA INFILE 'x' INTO TABLE t", 'load_data'],
            'call' => ['CALL proc(1)', 'call'],
            // disguises
            'lower case' => ['drop table t', 'ddl'],
            'leading whitespace' => ["  \n\t DROP TABLE t", 'ddl'],
            'leading block comment' => ['/* x */ DROP TABLE t', 'ddl'],
            'leading hint comment' => ['/*+ SET_VAR(a=1) */ ALTER TABLE t ADD c int', 'ddl'],
            'leading line comment' => ["-- c\nDROP TABLE t", 'ddl'],
            'leading hash comment' => ["# c\nDROP TABLE t", 'ddl'],
            'second statement' => ['SELECT 1; DROP TABLE t', 'ddl'],
            'second statement after write' => ['INSERT INTO t VALUES (1); COMMIT', 'transaction_control'],
            // safe
            'select' => ['SELECT * FROM t WHERE a = 1', null],
            'select for update' => ['SELECT * FROM t FOR UPDATE', null],
            'insert' => ['INSERT INTO t (a) VALUES (1)', null],
            'update' => ['UPDATE t SET a = 1', null],
            'delete' => ['DELETE FROM t', null],
            'savepoint' => ['SAVEPOINT trans2', null],
            'rollback to savepoint' => ['ROLLBACK TO SAVEPOINT trans2', null],
            'release savepoint' => ['RELEASE SAVEPOINT trans2', null],
            'create temporary table' => ['CREATE TEMPORARY TABLE tmp (a int)', null],
            'drop temporary table' => ['DROP TEMPORARY TABLE tmp', null],
            'show' => ['SHOW TABLES', null],
            'explain' => ['EXPLAIN SELECT 1', null],
            'a word that merely starts like a keyword' => ['SELECT begin_at, commit_id FROM t', null],
            'column named drop in a select' => ['SELECT `drop` FROM t', null],
            'set other variable' => ['SET NAMES utf8mb4', null],
            'subselect in parentheses' => ['(SELECT 1) UNION (SELECT 2)', null],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('statements')]
    public function testImplicitCommitReason(string $sql, ?string $expected): void
    {
        $this->assertSame($expected, RollbackGuard::implicitCommitReason($sql));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function writes(): array
    {
        return [
            'insert' => ['INSERT INTO t VALUES (1)', true],
            'update' => ['update t set a = 1', true],
            'delete' => ['DELETE FROM t', true],
            'replace' => ['REPLACE INTO t VALUES (1)', true],
            'select' => ['SELECT * FROM t', false],
            'comment then insert' => ['/* c */ INSERT INTO t VALUES (1)', true],
        ];
    }

    #[DataProvider('writes')]
    public function testIsWrite(string $sql, bool $expected): void
    {
        $this->assertSame($expected, RollbackGuard::isWrite($sql));
    }

    public function testWriteOnAnotherConnectionIsNotCovered(): void
    {
        $this->assertTrue(RollbackGuard::otherConnectionWrite('audit', 'mysql', 'INSERT INTO log VALUES (1)'));
        $this->assertFalse(RollbackGuard::otherConnectionWrite('mysql', 'mysql', 'INSERT INTO log VALUES (1)'));
        $this->assertFalse(RollbackGuard::otherConnectionWrite('audit', 'mysql', 'SELECT 1'));
    }

    public function testChangedTables(): void
    {
        $this->assertSame([], RollbackGuard::changedTables(['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1]));
        $this->assertSame(['b'], RollbackGuard::changedTables(['a' => 1, 'b' => 2], ['a' => 1, 'b' => 3]));
        $this->assertSame(['c'], RollbackGuard::changedTables(['a' => 1], ['a' => 1, 'c' => 0]));
        $this->assertSame(['a'], RollbackGuard::changedTables(['a' => 1], []));
    }

    public function testTransactionLevel(): void
    {
        $this->assertTrue(RollbackGuard::transactionStillOpen(1));
        $this->assertTrue(RollbackGuard::transactionStillOpen(2));
        $this->assertFalse(RollbackGuard::transactionStillOpen(0));
    }
}
