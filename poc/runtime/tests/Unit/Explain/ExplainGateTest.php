<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Explain;

use PeakSim\Runtime\Config\MeasureRefused;
use PeakSim\Runtime\Explain\ExplainGate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExplainGateTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function refused(): array
    {
        return [
            'a DELETE as it is' => ['delete from `references` where from_type = ? and from_id in (?, ?)'],
            'an UPDATE as it is' => ['update `deletions` set deleted_by = ? where id = ?'],
            'an INSERT as it is' => ['insert into `activities` (`type`) values (?)'],
            'a SELECT without EXPLAIN' => ['select * from users where id = ?'],
            'ANALYZE TABLE' => ['ANALYZE TABLE users'],
            'a plain EXPLAIN (a different format)' => ['EXPLAIN select * from users where id = ?'],
            'EXPLAIN with another format' => ['EXPLAIN FORMAT=TREE select * from users where id = ?'],
            'EXPLAIN ANALYZE (it runs the statement)' => ['EXPLAIN ANALYZE select * from users where id = ?'],
            'lower case prefix' => ['explain format=json select 1'],
            'a space in front' => [' EXPLAIN FORMAT=JSON select 1'],
            'empty' => [''],
            'two statements' => ['EXPLAIN FORMAT=JSON select 1; delete from users'],
            'two statements, DROP' => ['EXPLAIN FORMAT=JSON select 1; DROP DATABASE x'],
            'a trailing semicolon' => ['EXPLAIN FORMAT=JSON select 1;'],
            'a semicolon after a closed quote' => ["EXPLAIN FORMAT=JSON select 'a'; delete from users"],
            'a semicolon after a comment' => ['EXPLAIN FORMAT=JSON select 1 /* x */ ; delete from users'],
            'a semicolon after a doubled quote' => ["EXPLAIN FORMAT=JSON select 'it''s'; delete from users"],
            'EXPLAIN of EXPLAIN' => ['EXPLAIN FORMAT=JSON EXPLAIN select 1'],
            'EXPLAIN of DROP' => ['EXPLAIN FORMAT=JSON drop table users'],
            'EXPLAIN of ALTER' => ['EXPLAIN FORMAT=JSON alter table users add c int'],
            'EXPLAIN of SET' => ['EXPLAIN FORMAT=JSON set @a = 1'],
            'EXPLAIN of a CALL' => ['EXPLAIN FORMAT=JSON call p()'],
            'a version comment that the server runs' => ['EXPLAIN FORMAT=JSON select 1 /*!50000 ; delete from users */'],
            'a MariaDB-style version comment' => ['EXPLAIN FORMAT=JSON select 1 /*M!100000 ; delete from users */'],
            'an unclosed quote' => ["EXPLAIN FORMAT=JSON select 'abc"],
            'an unclosed comment' => ['EXPLAIN FORMAT=JSON select 1 /* abc'],
            'an unclosed backtick' => ['EXPLAIN FORMAT=JSON select `abc'],
            'a statement hidden after a -- comment line break' => ["EXPLAIN FORMAT=JSON select 1 -- c\n; delete from users"],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function allowed(): array
    {
        return [
            'a SELECT' => ['EXPLAIN FORMAT=JSON select * from `users` where `id` = ? limit 1'],
            'a SELECT in parentheses (a union branch)' => ['EXPLAIN FORMAT=JSON (select 1) union (select 2)'],
            'a WITH' => ['EXPLAIN FORMAT=JSON with t as (select 1) select * from t'],
            'an INSERT' => ['EXPLAIN FORMAT=JSON insert into `activities` (`type`) values (?)'],
            'an UPDATE' => ['EXPLAIN FORMAT=JSON update `deletions` set deleted_by = ? where id = ?'],
            'a DELETE' => ['EXPLAIN FORMAT=JSON delete from `references` where from_type = ? and from_id in (?, ?)'],
            'a REPLACE' => ['EXPLAIN FORMAT=JSON replace into t (a) values (?)'],
            'a semicolon inside a text' => ["EXPLAIN FORMAT=JSON select * from t where a = 'x;y' and b = ?"],
            'a semicolon inside a quoted name' => ['EXPLAIN FORMAT=JSON select `a;b` from t'],
            'a semicolon inside a double-quoted text' => ['EXPLAIN FORMAT=JSON select * from t where a = "x;y"'],
            'a semicolon inside a comment' => ['EXPLAIN FORMAT=JSON select 1 /* a; b */'],
            'a semicolon inside a line comment' => ["EXPLAIN FORMAT=JSON select 1 -- a; b\n"],
            'a backslash-escaped quote then a semicolon in the text' => ["EXPLAIN FORMAT=JSON select * from t where a = 'it\\'s;ok'"],
            'a doubled quote then a semicolon in the text' => ["EXPLAIN FORMAT=JSON select * from t where a = 'it''s;ok'"],
            'an optimizer hint comment' => ['EXPLAIN FORMAT=JSON select /*+ MAX_EXECUTION_TIME(1000) */ 1'],
        ];
    }

    #[DataProvider('refused')]
    public function testItRefusesBeforeAnythingIsSent(string $sql): void
    {
        $this->expectException(MeasureRefused::class);
        ExplainGate::assertSendable($sql);
    }

    #[DataProvider('allowed')]
    public function testItLetsOneExplainOfADataStatementThrough(string $sql): void
    {
        ExplainGate::assertSendable($sql);
        $this->addToAssertionCount(1);
    }

    public function testTheRefusalCarriesExitCodeTen(): void
    {
        $this->assertSame(10, MeasureRefused::EXIT_CODE);
        try {
            ExplainGate::assertSendable('delete from users');
            $this->fail('the gate let a bare DELETE through');
        } catch (MeasureRefused $e) {
            $this->assertStringContainsString('EXPLAIN FORMAT=JSON', $e->getMessage());
        }
    }

    public function testTheRefusalMessageIsOneShortLine(): void
    {
        try {
            ExplainGate::assertSendable("delete from users where x = '" . str_repeat('a', 5000) . "'\nsecond line");
            $this->fail('expected a refusal');
        } catch (MeasureRefused $e) {
            $this->assertStringNotContainsString("\n", $e->getMessage());
            $this->assertLessThan(300, strlen($e->getMessage()));
        }
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function kinds(): array
    {
        return [
            'select' => ['select 1', true],
            'select, upper case' => ['SELECT 1', true],
            'a parenthesised select' => ['(select 1) union (select 2)', true],
            'with' => ['with t as (select 1) select * from t', true],
            'insert' => ['insert into t values (1)', true],
            'update' => ['update t set a = 1', true],
            'delete' => ['delete from t', true],
            'replace' => ['replace into t values (1)', true],
            'begin' => ['begin', false],
            'savepoint' => ['savepoint trans2', false],
            'rollback to' => ['rollback to savepoint trans2', false],
            'set' => ['set names utf8mb4', false],
            'show' => ['show tables', false],
            'a leading space' => ['  select 1', true],
        ];
    }

    #[DataProvider('kinds')]
    public function testItNamesTheKindsThatCanBeExplained(string $sql, bool $explainable): void
    {
        $this->assertSame($explainable, ExplainGate::isExplainableKind($sql));
    }
}
