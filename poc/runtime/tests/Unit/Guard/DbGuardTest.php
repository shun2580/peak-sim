<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Guard;

use PeakSim\Runtime\Config\MeasureRefused;
use PeakSim\Runtime\Guard\DbGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DbGuardTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function refusedNames(): array
    {
        return [
            'empty' => [''],
            'app' => ['app'],
            'app_test' => ['app_test'],
            'production-looking' => ['production'],
            'laravel default' => ['laravel'],
            'prefix only' => ['peak_sim_measure_'],
            'prefix and too short a tail' => ['peak_sim_measure_abc'],
            'wrong prefix' => ['peak_sim_measured_abcdefgh'],
            'prefix not at the start' => ['x_peak_sim_measure_abcdefgh'],
            'upper case' => ['peak_sim_measure_ABCDEFGH'],
            'trailing newline' => ["peak_sim_measure_abcdefgh\n"],
            'too long (65 chars)' => ['peak_sim_measure_' . str_repeat('a', 48)],
            'backtick' => ['peak_sim_measure_abc`def'],
            'semicolon injection' => ["peak_sim_measure_x'; DROP DATABASE app; --"],
            'space' => ['peak_sim_measure_abc defgh'],
            'dot (schema.table)' => ['peak_sim_measure_abc.defgh'],
            'wildcard' => ['peak_sim_measure_%'],
            'dash' => ['peak_sim_measure_abc-defgh'],
            'nul byte' => ["peak_sim_measure_abcdefgh\0"],
            'multibyte' => ['peak_sim_measure_abcdefgé'],
            'the value of a typical .env DB_DATABASE' => ['laravel_app'],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function allowedNames(): array
    {
        return [
            'run id and scale' => ['peak_sim_measure_a1b2c3d4e5f6_1000'],
            'shortest tail (8)' => ['peak_sim_measure_abcdefgh'],
            'longest tail (47, 64 chars in all)' => ['peak_sim_measure_' . str_repeat('a', 47)],
        ];
    }

    #[DataProvider('refusedNames')]
    public function testRefusesNameThatIsNotAMeasurementDatabase(string $name): void
    {
        $this->assertFalse(DbGuard::isMeasureDbName($name));
        $this->expectException(MeasureRefused::class);
        DbGuard::assertDbName($name);
    }

    #[DataProvider('allowedNames')]
    public function testAllowsMeasurementDatabaseName(string $name): void
    {
        $this->assertTrue(DbGuard::isMeasureDbName($name));
        DbGuard::assertDbName($name);
    }

    public function testLongestAllowedNameFitsMysqlLimit(): void
    {
        $this->assertSame(64, strlen('peak_sim_measure_' . str_repeat('a', 47)));
    }

    /** @return array<string, array{0: string}> */
    public static function refusedHosts(): array
    {
        return [
            'empty' => [''],
            'localhost' => ['localhost'],
            'loopback' => ['127.0.0.1'],
            'the host DB of a .env' => ['db'],
            'other compose service' => ['mysql'],
            'public name' => ['db.example.com'],
            'private ip literal' => ['10.0.0.5'],
            'allowed name with suffix' => ['measure-db.example.com'],
            'upper case' => ['MEASURE-DB'],
            'trailing space' => ['measure-db '],
            'trailing newline' => ["measure-db\n"],
        ];
    }

    #[DataProvider('refusedHosts')]
    public function testRefusesHostThatIsNotTheDbService(string $host): void
    {
        $this->expectException(MeasureRefused::class);
        DbGuard::assertHost($host);
    }

    public function testAllowsTheDbServiceHost(): void
    {
        DbGuard::assertHost('measure-db');
        $this->addToAssertionCount(1);
    }

    /** @return array<string, array{0: string}> */
    public static function refusedUsers(): array
    {
        return ['root' => ['root'], 'app' => ['app'], 'empty' => [''], 'upper' => ['MEASURE'], 'with suffix' => ['measure2']];
    }

    #[DataProvider('refusedUsers')]
    public function testRefusesUserOtherThanMeasure(string $user): void
    {
        $this->expectException(MeasureRefused::class);
        DbGuard::assertUser($user);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function ipv4(): array
    {
        return [
            '10.x' => ['10.1.2.3', true],
            '172.16' => ['172.16.0.1', true],
            '172.31' => ['172.31.255.255', true],
            '172.32 is public' => ['172.32.0.1', false],
            '172.15 is public' => ['172.15.0.1', false],
            '192.168' => ['192.168.1.1', true],
            '192.169 is public' => ['192.169.1.1', false],
            'public' => ['93.184.216.34', false],
            'loopback' => ['127.0.0.1', false],
            'unresolved name comes back as is' => ['measure-db', false],
            'ipv6' => ['fd00::1', false],
        ];
    }

    #[DataProvider('ipv4')]
    public function testPrivateAddressRange(string $ip, bool $private): void
    {
        $this->assertSame($private, DbGuard::isPrivateIpv4($ip));
    }

    public function testRefusesHostResolvingToPublicAddress(): void
    {
        $this->expectException(MeasureRefused::class);
        DbGuard::assertResolvesPrivate('measure-db', static fn (string $h): string => '93.184.216.34');
    }

    public function testRefusesHostThatDoesNotResolve(): void
    {
        $this->expectException(MeasureRefused::class);
        DbGuard::assertResolvesPrivate('measure-db', static fn (string $h): string => $h);
    }

    public function testAcceptsHostResolvingToPrivateAddress(): void
    {
        DbGuard::assertResolvesPrivate('measure-db', static fn (string $h): string => '172.20.0.2');
        $this->addToAssertionCount(1);
    }

    public function testRefusalMessageIsOneBoundedLine(): void
    {
        try {
            DbGuard::assertDbName("evil\n" . str_repeat('x', 500));
            $this->fail('expected a refusal');
        } catch (MeasureRefused $e) {
            $this->assertStringNotContainsString("\n", $e->getMessage());
            $this->assertLessThan(300, strlen($e->getMessage()));
        }
    }
}
