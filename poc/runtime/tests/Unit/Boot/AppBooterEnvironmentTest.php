<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Boot;

use PeakSim\Runtime\Boot\AppBooter;
use PeakSim\Runtime\Boot\ScratchDir;
use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Config\MeasureRefused;
use PHPUnit\Framework\TestCase;

final class AppBooterEnvironmentTest extends TestCase
{
    private ?ScratchDir $scratch = null;

    protected function tearDown(): void
    {
        $this->scratch?->remove();
    }

    private function config(): MeasureConfig
    {
        return MeasureConfig::fromEnv([
            'PEAK_MEASURE_DB_HOST' => 'measure-db',
            'PEAK_MEASURE_DB_USER' => 'measure',
            'PEAK_MEASURE_DB_PASSWORD' => 'pw-for-test',
            'PEAK_MEASURE_RUN_ID' => 'abc123def456',
            'PEAK_MEASURE_APP_ROOT' => '/app',
        ]);
    }

    /** @return array<string, string> */
    private function environment(): array
    {
        $this->scratch = ScratchDir::create('abc123def456');
        $config = $this->config();

        return AppBooter::environment($config, $config->dbNameForScale(100), $this->scratch, 'base64:KEY');
    }

    public function testDatabaseSettingsAreTheMeasurementOnes(): void
    {
        $env = $this->environment();
        self::assertSame('mysql', $env['DB_CONNECTION']);
        self::assertSame('measure-db', $env['DB_HOST']);
        self::assertSame('3306', $env['DB_PORT']);
        self::assertSame('peak_sim_measure_abc123def456_100', $env['DB_DATABASE']);
        self::assertSame('measure', $env['DB_USERNAME']);
        self::assertSame('pw-for-test', $env['DB_PASSWORD']);
    }

    public function testAUrlCannotOverrideTheConnection(): void
    {
        $env = $this->environment();
        self::assertSame('', $env['DB_URL']);
        self::assertSame('', $env['DATABASE_URL']);
    }

    public function testSideEffectDriversAreInert(): void
    {
        $env = $this->environment();
        self::assertSame('array', $env['CACHE_STORE']);
        self::assertSame('array', $env['CACHE_DRIVER']);
        self::assertSame('array', $env['SESSION_DRIVER']);
        self::assertSame('array', $env['MAIL_MAILER']);
        self::assertSame('null', $env['BROADCAST_CONNECTION']);
        self::assertSame('null', $env['SCOUT_DRIVER']);
        self::assertSame('sync', $env['QUEUE_CONNECTION']);
        self::assertSame('stderr', $env['LOG_CHANNEL']);
    }

    public function testEveryPathTheAppWritesIsInTheScratchDir(): void
    {
        $env = $this->environment();
        $root = $this->scratch->path;
        foreach (['APP_PACKAGES_CACHE', 'APP_SERVICES_CACHE', 'APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE', 'VIEW_COMPILED_PATH'] as $key) {
            self::assertStringStartsWith($root . '/', $env[$key], $key);
        }
    }

    public function testTheAppKeyIsGivenByTheTool(): void
    {
        self::assertSame('base64:KEY', $this->environment()['APP_KEY']);
        self::assertMatchesRegularExpression('#^base64:[A-Za-z0-9+/]{43}=$#', AppBooter::newAppKey());
        self::assertNotSame(AppBooter::newAppKey(), AppBooter::newAppKey());
    }

    public function testRefusesADatabaseNameThatIsNotAMeasurementDatabase(): void
    {
        $this->scratch = ScratchDir::create('abc123def456');
        $this->expectException(MeasureRefused::class);
        AppBooter::environment($this->config(), 'app', $this->scratch, 'base64:KEY');
    }

    public function testNothingInTheEnvironmentIsTheTargetAppsOwnEnvFile(): void
    {
        $env = $this->environment();
        self::assertStringNotContainsString('.env', implode("\n", $env));
    }
}
