<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Guard;

use PeakSim\Runtime\Config\MeasureRefused;
use PeakSim\Runtime\Guard\DbExecutor;
use PeakSim\Runtime\Guard\MeasureDb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MeasureDbTest extends TestCase
{
    private function recorder(array $existing = []): object
    {
        return new class ($existing) implements DbExecutor {
            /** @var list<string> */
            public array $sent = [];

            /** @param list<string> $existing */
            public function __construct(private array $existing)
            {
            }

            public function exec(string $sql): void
            {
                $this->sent[] = $sql;
            }

            public function column(string $sql): array
            {
                $this->sent[] = $sql;

                return $this->existing;
            }
        };
    }

    public function testCreateSendsOneCreateForAValidName(): void
    {
        $db = $this->recorder();
        (new MeasureDb($db))->create('peak_sim_measure_a1b2c3d4e5f6_100');

        $this->assertSame(
            ['CREATE DATABASE `peak_sim_measure_a1b2c3d4e5f6_100` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'],
            $db->sent,
        );
    }

    public function testDropSendsOneDropIfExistsForAValidName(): void
    {
        $db = $this->recorder();
        (new MeasureDb($db))->drop('peak_sim_measure_a1b2c3d4e5f6_100');

        $this->assertSame(['DROP DATABASE IF EXISTS `peak_sim_measure_a1b2c3d4e5f6_100`'], $db->sent);
    }

    /** @return array<string, array{0: string}> */
    public static function badNames(): array
    {
        return [
            'app' => ['app'],
            'app_test' => ['app_test'],
            'prefix only' => ['peak_sim_measure_'],
            'injection' => ["peak_sim_measure_x'; DROP DATABASE app; --"],
            'backtick break-out' => ['peak_sim_measure_abc`; DROP DATABASE app; -- `'],
            'empty' => [''],
        ];
    }

    #[DataProvider('badNames')]
    public function testDropRefusesBadNameWithoutSendingAnySql(string $name): void
    {
        $db = $this->recorder();
        try {
            (new MeasureDb($db))->drop($name);
            $this->fail('expected a refusal');
        } catch (MeasureRefused) {
            $this->assertSame([], $db->sent);
        }
    }

    #[DataProvider('badNames')]
    public function testCreateRefusesBadNameWithoutSendingAnySql(string $name): void
    {
        $db = $this->recorder();
        try {
            (new MeasureDb($db))->create($name);
            $this->fail('expected a refusal');
        } catch (MeasureRefused) {
            $this->assertSame([], $db->sent);
        }
    }

    public function testListKeepsOnlyNamesThatPassTheGuard(): void
    {
        $db = $this->recorder(['peak_sim_measure_a1b2c3d4e5f6_100', 'peak_sim_measure_', 'peak_sim_measureXabcdefgh', 'peak_sim_measure_zzzzzzzz_10']);

        $this->assertSame(
            ['peak_sim_measure_a1b2c3d4e5f6_100', 'peak_sim_measure_zzzzzzzz_10'],
            (new MeasureDb($db))->listMeasureDatabases(),
        );
    }

    public function testDropAllDropsOnlyGuardedNames(): void
    {
        $db = $this->recorder(['peak_sim_measure_a1b2c3d4e5f6_100', 'app', 'peak_sim_measure_a1b2c3d4e5f6_1000']);
        $dropped = (new MeasureDb($db))->dropAll();

        $this->assertSame(['peak_sim_measure_a1b2c3d4e5f6_100', 'peak_sim_measure_a1b2c3d4e5f6_1000'], $dropped);
        $drops = array_values(array_filter($db->sent, static fn (string $s): bool => str_starts_with($s, 'DROP')));
        $this->assertSame([
            'DROP DATABASE IF EXISTS `peak_sim_measure_a1b2c3d4e5f6_100`',
            'DROP DATABASE IF EXISTS `peak_sim_measure_a1b2c3d4e5f6_1000`',
        ], $drops);
    }
}
