<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

use PeakSim\Runtime\Boot\AppBooter;
use PeakSim\Runtime\Boot\BootFailure;
use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Config\MeasureRefused;
use PeakSim\Runtime\Data\Generator;
use PeakSim\Runtime\Data\ScalePlan;
use PeakSim\Runtime\Guard\DbGuard;
use PeakSim\Runtime\Guard\MeasureDb;

/**
 * Makes one measurement database, inside one process (it starts the target app, which cannot be started twice):
 * drop and create the database, run the app's migrations, then fill it by the recipe.
 *
 *   seeder     the verification app's own `DatabaseSeeder` with SEED_* sizes (customers, products, orders, items)
 *   generator  the tool's `Generator` from a `ScalePlan` (targeted or global)
 *
 * With a profile that has an `after_generation` hook, the hook runs right after the generator, on the same connection
 * (see `afterGeneration`). The seeder mode ignores the profile.
 *
 * The row counts are read back and compared with the recipe; a database that does not hold what was asked for is a
 * BuildFailure, never a measurement on the wrong data.
 */
final class DatabaseBuild
{
    /**
     * @param array<string, mixed> $recipe
     * @param array<string, mixed> $profile app knowledge for the generator (ignored by the seeder)
     *
     * @return array<string, mixed> what was built
     *
     * @throws BuildFailure
     */
    public function run(MeasureConfig $config, int $scale, string $mode, array $recipe, array $profile = []): array
    {
        $name = $config->dbNameForScale($scale);
        $started = microtime(true);

        $measureDb = MeasureDb::connect($config);
        $measureDb->drop($name);
        $measureDb->create($name);

        if ($mode === 'seeder') {
            foreach (['customers' => 'SEED_CUSTOMERS', 'products' => 'SEED_PRODUCTS', 'orders' => 'SEED_ORDERS', 'items' => 'SEED_ITEMS_PER_ORDER'] as $key => $variable) {
                $value = (string) (int) ($recipe[$key] ?? 0);
                putenv($variable . '=' . $value);
                $_ENV[$variable] = $value;
                $_SERVER[$variable] = $value;
            }
        }

        try {
            $booted = (new AppBooter())->boot($config, $scale);
        } catch (BootFailure $e) {
            throw new BuildFailure('the app did not start: ' . $e->getMessage(), 0, $e);
        }
        $app = $booted->app;
        $console = $app->make('Illuminate\\Contracts\\Console\\Kernel');

        $migrated = microtime(true);
        if ($console->call('migrate', ['--force' => true]) !== 0) {
            throw new BuildFailure('migrate failed: ' . self::tail((string) $console->output()));
        }
        $migrateSeconds = round(microtime(true) - $migrated, 2);

        $filled = microtime(true);
        $info = ['database' => $name, 'mode' => $mode, 'scale' => $scale, 'laravel' => $app->version(), 'migrate_seconds' => $migrateSeconds];
        if ($mode === 'seeder') {
            if ($console->call('db:seed', ['--force' => true]) !== 0) {
                throw new BuildFailure('db:seed failed: ' . self::tail((string) $console->output()));
            }
        } else {
            $pdo = $app['db']->connection()->getPdo();
            $report = (new Generator())->generate($pdo, self::scalePlan($recipe), $profile, self::morphLinks($app));
            $afterGeneration = self::afterGeneration($profile, $pdo, $name);
            $info['generation'] = [
                'scope' => $report->scope,
                'generated' => $report->generatedCount(),
                'failed' => $report->failedCount(),
                'skipped' => $report->skippedCount(),
                'reasons' => $report->reasonCounts(),
                'failed_tables' => array_values(array_map(
                    static fn ($o): array => ['table' => $o->table, 'reason' => $o->reason, 'detail' => $o->detail],
                    array_filter($report->outcomes, static fn ($o): bool => $o->status === 'failed'),
                )),
            ];
            if ($afterGeneration !== null) {
                $info['after_generation'] = $afterGeneration;
            }
        }
        $info['fill_seconds'] = round(microtime(true) - $filled, 2);

        $counts = self::counts($app['db']->connection()->getPdo(), $name);
        $info['rows'] = $counts;
        if ($mode === 'seeder') {
            $problem = self::seederMismatch($recipe, $counts);
            if ($problem !== null) {
                throw new BuildFailure($problem);
            }
        }
        $info['total_seconds'] = round(microtime(true) - $started, 2);

        return $info;
    }

    /**
     * Runs the profile's `after_generation` hook (reference rows, logins, fixups: what the app needs that generated
     * rows cannot give) on the connection the generator just filled. Nothing runs unless the name is a measurement
     * database (the same guard as for creating and dropping one), and on MySQL the connection must be on that
     * database. A hook that fails stops the build: a database the profile could not finish is not measured on.
     *
     * @param array<string, mixed> $profile
     *
     * @return array<string, mixed>|null what the hook reports, or null when the profile has no hook
     *
     * @throws BuildFailure
     * @throws MeasureRefused
     */
    public static function afterGeneration(array $profile, \PDO $pdo, string $name): ?array
    {
        if (!array_key_exists('after_generation', $profile)) {
            return null;
        }
        DbGuard::assertDbName($name);
        $hook = $profile['after_generation'];
        if (!is_callable($hook)) {
            throw new BuildFailure('the profile\'s after_generation is not a function');
        }
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $current = $pdo->query('SELECT DATABASE()')->fetchColumn();
            if ($current !== $name) {
                throw new MeasureRefused(sprintf('refused: the connection is on %s, not on the measurement database', is_string($current) ? json_encode(substr($current, 0, 80)) : 'no database'));
            }
        }
        try {
            $summary = $hook($pdo, $name);
        } catch (MeasureRefused $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new BuildFailure('the profile\'s after_generation failed: ' . self::tail($e->getMessage()), 0, $e);
        }

        return is_array($summary) ? $summary : [];
    }

    /**
     * The polymorphic relations the started app declares (see MorphDiscovery). Nothing found, or a failure to look, is
     * an empty list: the generator then fills such columns as it always did.
     *
     * @return list<\PeakSim\Runtime\Data\MorphLink>
     */
    private static function morphLinks(object $app): array
    {
        try {
            return MorphDiscovery::discover(MorphDiscovery::appModels($app));
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<string, mixed> $recipe */
    public static function scalePlan(array $recipe): ScalePlan
    {
        $base = (int) ($recipe['base'] ?? 5);
        if (($recipe['mode'] ?? null) === 'global') {
            return ScalePlan::global((int) $recipe['n'], $base);
        }
        if (($recipe['mode'] ?? null) !== 'targeted') {
            throw new BuildFailure('the generator recipe has no mode');
        }

        return ScalePlan::targeted(
            array_values(array_map('strval', (array) $recipe['n_tables'])),
            isset($recipe['m_table']) ? (string) $recipe['m_table'] : null,
            isset($recipe['m_per']) ? (string) $recipe['m_per'] : null,
            (int) $recipe['n'],
            (int) ($recipe['m'] ?? 0),
            $base,
        );
    }

    /**
     * The rows the verification app's seeder must have written for these sizes, against what is there.
     *
     * @param array<string, mixed> $recipe
     * @param array<string, int>   $counts
     */
    public static function seederMismatch(array $recipe, array $counts): ?string
    {
        $orders = (int) ($recipe['orders'] ?? 0);
        $hasParents = (int) ($recipe['customers'] ?? 0) >= 1 && (int) ($recipe['products'] ?? 0) >= 1;
        $expected = [
            'customers' => (int) ($recipe['customers'] ?? 0),
            'products' => (int) ($recipe['products'] ?? 0),
            'orders' => $hasParents ? $orders : 0,
            'items' => $hasParents ? $orders * (int) ($recipe['items'] ?? 0) : 0,
        ];
        foreach ($expected as $table => $rows) {
            if (!array_key_exists($table, $counts)) {
                return sprintf('the seeder wrote no table "%s" (the app is not the verification app?)', $table);
            }
            if ($counts[$table] !== $rows) {
                return sprintf('the seeder was asked for %d rows of %s and the table holds %d', $rows, $table, $counts[$table]);
            }
        }

        return null;
    }

    /** @return array<string, int> table => rows, for the tables of the measurement database */
    private static function counts(\PDO $pdo, string $name): array
    {
        $statement = $pdo->prepare("select table_name from information_schema.tables where table_schema = ? and table_type = 'BASE TABLE' order by table_name");
        $statement->execute([$name]);
        $counts = [];
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $table = (string) $table;
            $counts[$table] = (int) $pdo->query('select count(*) from `' . str_replace('`', '``', $table) . '`')->fetchColumn();
        }

        return $counts;
    }

    private static function tail(string $text): string
    {
        return substr(trim(preg_replace('/\s+/', ' ', $text) ?? $text), -400);
    }
}
