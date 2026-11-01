<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Boot;

use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Config\MeasureRefused;
use PeakSim\Runtime\Guard\DbGuard;

/**
 * Starts the target app inside this process, from the tool's side (safety conditions 2, 4 and 5).
 *
 *  - The tool reads the app's `vendor/autoload.php` and `bootstrap/app.php`. It writes nothing there and adds no
 *    package. The app's own `.env` is never the source of a value: every setting that matters is an environment
 *    variable, which Laravel prefers over `.env`.
 *  - Everything the app writes (storage, compiled views, manifests) is sent to a scratch directory.
 *  - After start-up every database connection is checked and, if needed, replaced, so no connection can reach
 *    anything but the measurement database.
 *  - The fakes of Fakes::install() are put in place before the first request.
 */
final class AppBooter
{
    /** Drivers whose connection is rewritten to point at the measurement database (the rest are replaced). */
    private const MYSQL_DRIVERS = ['mysql', 'mariadb'];

    public function boot(MeasureConfig $config, int $scale): BootedApp
    {
        $dbName = $config->dbNameForScale($scale);
        $appRoot = $config->appRoot;
        foreach (['vendor/autoload.php' => 'vendor_missing', 'bootstrap/app.php' => 'not_a_laravel_app'] as $file => $code) {
            if (!is_file($appRoot . '/' . $file)) {
                throw new BootFailure(sprintf('%s: %s not found under the app root', $code, $file));
            }
        }

        $scratch = ScratchDir::create($config->runId);
        register_shutdown_function(static fn () => $scratch->remove());

        self::export(self::environment($config, $dbName, $scratch, self::newAppKey()));
        ini_set('display_errors', 'stderr');
        ini_set('default_socket_timeout', '3');
        chdir($appRoot);

        try {
            require_once $appRoot . '/vendor/autoload.php';
            $app = require $appRoot . '/bootstrap/app.php';
            $app->useStoragePath($scratch->storagePath());
            $kernel = $app->make('Illuminate\\Contracts\\Http\\Kernel');
            // Like Kernel::handle(), bind a request before the providers boot: many of them (the URL generator,
            // route model binding, auth guards) need one. Laravel 12 apps fail to start without it.
            $request = 'Illuminate\\Http\\Request';
            $facade = 'Illuminate\\Support\\Facades\\Facade';
            $app->instance('request', $request::create('http://measure.invalid/'));
            $facade::clearResolvedInstance('request');
            $kernel->bootstrap();
        } catch (\Throwable $e) {
            throw new BootFailure('the app did not start: ' . get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 200), 0, $e);
        }

        $report = ['db_name' => $dbName, 'laravel' => $app->version(), 'php' => PHP_VERSION];
        $report['connections'] = self::lockDownConnections($app, $config, $dbName);

        $sink = new ExceptionSink();
        $handler = $app->make('Illuminate\\Contracts\\Debug\\ExceptionHandler');
        if (method_exists($handler, 'reportable')) {
            $handler->reportable(static function (\Throwable $e) use ($sink): void {
                $sink->record($e);
            });
        }

        $ledger = new ExternalCallLedger();
        $report['fakes'] = Fakes::install($app, $ledger, $scratch);

        return new BootedApp($app, $kernel, $sink, $ledger, $scratch, $dbName, $report);
    }

    /**
     * Every value the app could take from its own configuration that matters to safety, as real environment
     * variables (these win over `.env`). Pure: tested without a database or Laravel.
     *
     * @return array<string, string>
     */
    public static function environment(MeasureConfig $config, string $dbName, ScratchDir $scratch, string $appKey): array
    {
        DbGuard::assertDbName($dbName);

        return [
            'APP_ENV' => 'local',
            'APP_DEBUG' => 'false',
            'APP_KEY' => $appKey,
            'APP_URL' => 'http://measure.invalid',
            // Behave like a web request, not like artisan (many packages branch on this).
            'APP_RUNNING_IN_CONSOLE' => 'false',

            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $config->dbHost,
            'DB_PORT' => (string) $config->dbPort,
            'DB_DATABASE' => $dbName,
            'DB_USERNAME' => $config->dbUser,
            'DB_PASSWORD' => $config->dbPassword,
            'DB_URL' => '',
            'DATABASE_URL' => '',

            'CACHE_STORE' => 'array',
            'CACHE_DRIVER' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'BROADCAST_CONNECTION' => 'null',
            'BROADCAST_DRIVER' => 'null',
            'SCOUT_DRIVER' => 'null',
            'FILESYSTEM_DISK' => 'local',
            'LOG_CHANNEL' => 'stderr',
            'TELESCOPE_ENABLED' => 'false',

            'APP_PACKAGES_CACHE' => $scratch->bootstrapCachePath('packages.php'),
            'APP_SERVICES_CACHE' => $scratch->bootstrapCachePath('services.php'),
            'APP_CONFIG_CACHE' => $scratch->bootstrapCachePath('config.php'),
            'APP_ROUTES_CACHE' => $scratch->bootstrapCachePath('routes.php'),
            'APP_EVENTS_CACHE' => $scratch->bootstrapCachePath('events.php'),
            'VIEW_COMPILED_PATH' => $scratch->viewsPath(),
        ];
    }

    public static function newAppKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    /** @param array<string, string> $env */
    private static function export(array $env): void
    {
        foreach ($env as $key => $value) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    /**
     * Makes every connection the app knows point at the measurement database, whatever its own configuration says
     * (a hard-coded host, a pgsql or sqlite connection, a `url`), then proves it. Returns the evidence.
     *
     * @return array<string, mixed>
     *
     * @throws MeasureRefused when a connection still does not point at the measurement database
     */
    private static function lockDownConnections(object $app, MeasureConfig $config, string $dbName): array
    {
        $configRepo = $app['config'];
        $db = $app['db'];
        $connections = (array) $configRepo->get('database.connections', []);
        $overridden = [];
        $replaced = [];

        foreach ($connections as $name => $settings) {
            $settings = (array) $settings;
            $driver = (string) ($settings['driver'] ?? '');
            if (in_array($driver, self::MYSQL_DRIVERS, true)) {
                $differs = ($settings['host'] ?? null) !== $config->dbHost
                    || ($settings['database'] ?? null) !== $dbName
                    || ($settings['username'] ?? null) !== $config->dbUser
                    || isset($settings['read']) || isset($settings['write']) || !empty($settings['url']) || !empty($settings['unix_socket']);
                if ($differs) {
                    $overridden[] = (string) $name;
                }
                unset($settings['read'], $settings['write'], $settings['sticky']);
                $measure = array_merge($settings, ['driver' => 'mysql']);
            } else {
                $replaced[(string) $name] = $driver;
                $measure = ['driver' => 'mysql', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'prefix_indexes' => true, 'strict' => true, 'engine' => null];
            }
            $measure = array_merge($measure, [
                'host' => $config->dbHost,
                'port' => $config->dbPort,
                'database' => $dbName,
                'username' => $config->dbUser,
                'password' => $config->dbPassword,
                'url' => null,
                'unix_socket' => '',
            ]);
            $configRepo->set('database.connections.' . $name, $measure);
            $db->purge((string) $name);
        }

        $default = (string) $configRepo->get('database.default');
        if (!array_key_exists($default, $connections)) {
            throw new MeasureRefused(sprintf('refused: the app\'s default connection "%s" is not a configured connection', $default));
        }

        // The proof: the config, then the server.
        foreach (array_keys($connections) as $name) {
            $connection = $db->connection((string) $name);
            $seen = [$connection->getDatabaseName(), $connection->getConfig('host'), $connection->getConfig('username')];
            if ($seen !== [$dbName, $config->dbHost, $config->dbUser]) {
                throw new MeasureRefused(sprintf('refused: connection "%s" does not point at the measurement database', $name));
            }
        }
        $actual = $db->connection($default)->selectOne('select database() as db');
        if (($actual->db ?? null) !== $dbName) {
            throw new MeasureRefused('refused: the server says the default connection is not on the measurement database');
        }

        return [
            'default' => $default,
            'names' => array_map('strval', array_keys($connections)),
            'overridden' => $overridden,
            'replaced_non_mysql' => $replaced,
            'verified_database' => $dbName,
        ];
    }
}
