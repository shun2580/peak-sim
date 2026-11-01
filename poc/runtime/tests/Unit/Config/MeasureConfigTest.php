<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Config;

use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Config\MeasureRefused;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MeasureConfigTest extends TestCase
{
    /** @return array<string, string> */
    private static function validEnv(): array
    {
        return [
            'PEAK_MEASURE_DB_HOST' => 'measure-db',
            'PEAK_MEASURE_DB_USER' => 'measure',
            'PEAK_MEASURE_DB_PASSWORD' => 'throwaway',
            'PEAK_MEASURE_RUN_ID' => 'a1b2c3d4e5f6',
            'PEAK_MEASURE_APP_ROOT' => '/app',
        ];
    }

    public function testReadsValidEnvironmentWithDefaults(): void
    {
        $config = MeasureConfig::fromEnv(self::validEnv());

        $this->assertSame('measure-db', $config->dbHost);
        $this->assertSame(3306, $config->dbPort);
        $this->assertSame('measure', $config->dbUser);
        $this->assertSame('a1b2c3d4e5f6', $config->runId);
        $this->assertSame('/app', $config->appRoot);
        $this->assertNull($config->staticJson);
        $this->assertSame([100, 1000, 10000], $config->scales);
        $this->assertSame('/out', $config->outDir);
    }

    public function testReadsOptionalValues(): void
    {
        $config = MeasureConfig::fromEnv(self::validEnv() + [
            'PEAK_MEASURE_DB_PORT' => '3307',
            'PEAK_MEASURE_STATIC_JSON' => '/in/analysis.json',
            'PEAK_MEASURE_SCALES' => '10,100,1000',
            'PEAK_MEASURE_OUT_DIR' => '/out/run1',
        ]);

        $this->assertSame(3307, $config->dbPort);
        $this->assertSame('/in/analysis.json', $config->staticJson);
        $this->assertSame([10, 100, 1000], $config->scales);
        $this->assertSame('/out/run1', $config->outDir);
    }

    public function testBuildsDatabaseNameFromRunIdAndScale(): void
    {
        $config = MeasureConfig::fromEnv(self::validEnv());

        $this->assertSame('peak_sim_measure_a1b2c3d4e5f6_1000', $config->dbNameForScale(1000));
    }

    public function testRefusesScaleThatIsNotConfigured(): void
    {
        $this->expectException(MeasureRefused::class);
        MeasureConfig::fromEnv(self::validEnv())->dbNameForScale(7);
    }

    /** @return array<string, array{0: string}> */
    public static function missingKeys(): array
    {
        return array_combine(
            ['host', 'user', 'password', 'run id', 'app root'],
            array_map(
                static fn (string $k): array => [$k],
                ['PEAK_MEASURE_DB_HOST', 'PEAK_MEASURE_DB_USER', 'PEAK_MEASURE_DB_PASSWORD', 'PEAK_MEASURE_RUN_ID', 'PEAK_MEASURE_APP_ROOT'],
            ),
        );
    }

    #[DataProvider('missingKeys')]
    public function testRefusesWhenARequiredKeyIsMissing(string $key): void
    {
        $env = self::validEnv();
        unset($env[$key]);
        $this->expectException(MeasureRefused::class);
        MeasureConfig::fromEnv($env);
    }

    public function testRefusesEmptyEnvironment(): void
    {
        $this->expectException(MeasureRefused::class);
        MeasureConfig::fromEnv([]);
    }

    /** @return array<string, array{0: array<string, string>}> */
    public static function badOverrides(): array
    {
        return [
            'host is localhost' => [['PEAK_MEASURE_DB_HOST' => 'localhost']],
            'host is a .env style name' => [['PEAK_MEASURE_DB_HOST' => 'db']],
            'host is a public name' => [['PEAK_MEASURE_DB_HOST' => 'db.example.com']],
            'user is root' => [['PEAK_MEASURE_DB_USER' => 'root']],
            'empty password' => [['PEAK_MEASURE_DB_PASSWORD' => '']],
            'port not a number' => [['PEAK_MEASURE_DB_PORT' => 'abc']],
            'port zero' => [['PEAK_MEASURE_DB_PORT' => '0']],
            'port too large' => [['PEAK_MEASURE_DB_PORT' => '70000']],
            'run id too short' => [['PEAK_MEASURE_RUN_ID' => 'abc']],
            'run id too long' => [['PEAK_MEASURE_RUN_ID' => str_repeat('a', 25)]],
            'run id upper case' => [['PEAK_MEASURE_RUN_ID' => 'ABCDEFGH']],
            'run id with underscore' => [['PEAK_MEASURE_RUN_ID' => 'abc_defgh']],
            'run id with injection' => [['PEAK_MEASURE_RUN_ID' => "abcdef'; DROP DATABASE app; --"]],
            'app root relative' => [['PEAK_MEASURE_APP_ROOT' => 'app']],
            'app root with ..' => [['PEAK_MEASURE_APP_ROOT' => '/app/../etc']],
            'static json relative' => [['PEAK_MEASURE_STATIC_JSON' => 'analysis.json']],
            'out dir relative' => [['PEAK_MEASURE_OUT_DIR' => 'out']],
            'scales not numbers' => [['PEAK_MEASURE_SCALES' => '10,abc,1000']],
            'scales zero' => [['PEAK_MEASURE_SCALES' => '0,10,100']],
            'scales descending' => [['PEAK_MEASURE_SCALES' => '1000,100,10']],
            'scales repeated' => [['PEAK_MEASURE_SCALES' => '10,10,100']],
            'scales only two points' => [['PEAK_MEASURE_SCALES' => '100,1000']],
            'scales too large' => [['PEAK_MEASURE_SCALES' => '10,100,10000000']],
        ];
    }

    /** @param array<string, string> $override */
    #[DataProvider('badOverrides')]
    public function testRefusesInvalidValue(array $override): void
    {
        $this->expectException(MeasureRefused::class);
        MeasureConfig::fromEnv($override + self::validEnv());
    }

    public function testNonStringValuesAreTreatedAsMissing(): void
    {
        $env = self::validEnv();
        $env['PEAK_MEASURE_DB_HOST'] = ['measure-db'];
        $this->expectException(MeasureRefused::class);
        MeasureConfig::fromEnv($env);
    }

    public function testIgnoresTheAppsOwnDatabaseVariables(): void
    {
        $config = MeasureConfig::fromEnv(self::validEnv() + [
            'DB_HOST' => '127.0.0.1',
            'DB_DATABASE' => 'app',
            'DATABASE_URL' => 'mysql://root@prod/app',
        ]);

        $this->assertSame('measure-db', $config->dbHost);
    }

    public function testRefusalExitCodeIsTen(): void
    {
        $this->assertSame(10, MeasureRefused::EXIT_CODE);
    }

    public function testGeneratedRunIdIsAcceptedAndDiffers(): void
    {
        $a = MeasureConfig::generateRunId();
        $b = MeasureConfig::generateRunId();
        $this->assertNotSame($a, $b);
        $config = MeasureConfig::fromEnv(['PEAK_MEASURE_RUN_ID' => $a] + self::validEnv());
        $this->assertSame($a, $config->runId);
    }
}
