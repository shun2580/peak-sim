<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Capture;

use PeakSim\Runtime\Capture\StatementShape;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** How texts are grouped into statement types. Every expected pattern is written by hand. */
final class StatementShapeTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function same(): array
    {
        return [
            'a placeholder stays' => ['select * from `a` where `id` = ?', 'select * from `a` where `id` = ?'],
            'a number literal' => ['select * from `a` where `id` = 7', 'select * from `a` where `id` = ?'],
            'a string literal' => ["select * from `a` where `kind` = 'audit'", 'select * from `a` where `kind` = ?'],
            'a string with a quote and an escape' => ["select * from `a` where `k` = 'it''s a \\' test'", 'select * from `a` where `k` = ?'],
            'a double-quoted string' => ['select * from `a` where `k` = "x y"', 'select * from `a` where `k` = ?'],
            'a decimal and a negative number' => ['select * from `a` where `p` > 1.5 and `q` > -3', 'select * from `a` where `p` > ? and `q` > ?'],
            'limit and offset' => ['select * from `a` limit 15 offset 30', 'select * from `a` limit ? offset ?'],
            'digits in an identifier are not a number' => ['select `col1`, t2.x from `t2` where `a` = 1', 'select `col1`, t2.x from `t2` where `a` = ?'],
            'whitespace is collapsed and the last semicolon dropped' => ["select *\n   from `a`\twhere `id` = ? ;", 'select * from `a` where `id` = ?'],
            'in with three' => ['select * from `a` where `id` in (?, ?, ?)', 'select * from `a` where `id` in (?)'],
            'in with one' => ['select * from `a` where `id` in (?)', 'select * from `a` where `id` in (?)'],
            'in with literals' => ['select * from `a` where `id` in (1, 2, 3, 4)', 'select * from `a` where `id` in (?)'],
            'not in' => ['select * from `a` where `id` not in (?,?)', 'select * from `a` where `id` not in (?)'],
            'in written in capitals keeps its case (the list still collapses)' => ['select * from `a` where `id` IN (?, ?)', 'select * from `a` where `id` IN (?)'],
            'two lists in one statement' => ['select * from `a` where `x` in (?, ?) and `y` in (?, ?, ?)', 'select * from `a` where `x` in (?) and `y` in (?)'],
            'a multi-row insert' => ['insert into `a` (`x`, `y`) values (?, ?), (?, ?), (?, ?)', 'insert into `a` (`x`, `y`) values (?, ?)'],
            'a one-row insert' => ['insert into `a` (`x`, `y`) values (?, ?)', 'insert into `a` (`x`, `y`) values (?, ?)'],
        ];
    }

    #[DataProvider('same')]
    public function testPattern(string $sql, string $expected): void
    {
        self::assertSame($expected, StatementShape::pattern($sql));
    }

    public function testTextsThatDifferOnlyInValuesAreOneType(): void
    {
        $a = StatementShape::pattern('select * from `t` where `id` in (?, ?, ?) and `s` = ? limit 15');
        $b = StatementShape::pattern("select * from `t` where `id` in (?, ?, ?, ?, ?, ?, ?) and `s` = 'paid' limit 30");
        self::assertSame($a, $b);
    }

    public function testDifferentStatementsStayDifferent(): void
    {
        self::assertNotSame(
            StatementShape::pattern('select * from `a` where `id` = ?'),
            StatementShape::pattern('select * from `b` where `id` = ?'),
        );
        self::assertNotSame(
            StatementShape::pattern('select * from `a` where `id` = ?'),
            StatementShape::pattern('select * from `a` where `id` > ?'),
        );
        self::assertNotSame(
            StatementShape::pattern('insert into `a` (`x`) values (?)'),
            StatementShape::pattern('insert into `a` (`x`, `y`) values (?, ?)'),
        );
    }

    public function testNoLiteralSurvivesInAPattern(): void
    {
        $pattern = StatementShape::pattern("update `users` set `name` = 'Alice Example', `age` = 41 where `email` = 'alice@example.test' and `id` in (7, 8)");
        self::assertSame('update `users` set `name` = ?, `age` = ? where `email` = ? and `id` in (?)', $pattern);
        self::assertStringNotContainsString('Alice', $pattern);
        self::assertStringNotContainsString('alice@', $pattern);
        self::assertStringNotContainsString('41', $pattern);
    }
}
