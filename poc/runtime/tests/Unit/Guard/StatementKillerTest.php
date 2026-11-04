<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Guard;

use PeakSim\Runtime\Guard\StatementKiller;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatementKillerTest extends TestCase
{
    /** @return array<string, array{string, string|null}> */
    public static function statements(): array
    {
        return [
            'insert' => ['INSERT INTO `role_permissions` (a) SELECT 1 FROM entities CROSS JOIN roles', 'role_permissions'],
            'insert ignore' => ["insert ignore into users (a) values (1)", 'users'],
            'replace' => ['REPLACE INTO `t1` VALUES (1)', 't1'],
            'update' => ['UPDATE entities SET a = 1', 'entities'],
            'delete' => ['delete from `pages` where id = 1', 'pages'],
            'select names its first table' => ['SELECT count(*) FROM `entities` WHERE a = 1', 'entities'],
            'qualified name' => ['INSERT INTO `peak_sim_measure_x_1`.`t` SELECT 1', 'peak_sim_measure_x_1.t'],
            'no table' => ['SELECT SLEEP(100)', null],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('statements')]
    public function test_it_names_the_table_a_statement_works_on(string $sql, ?string $table): void
    {
        $this->assertSame($table, StatementKiller::tableOf($sql));
    }

    public function test_it_refuses_a_database_that_is_not_a_measurement_database(): void
    {
        $this->expectException(\PeakSim\Runtime\Config\MeasureRefused::class);
        (new StatementKiller((new \ReflectionClass(\PDO::class))->newInstanceWithoutConstructor()))->killOn('mysql');
    }
}
