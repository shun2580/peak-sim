<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Measure;

use PDO;
use PeakSim\Runtime\Config\MeasureRefused;
use PeakSim\Runtime\Measure\BuildFailure;
use PeakSim\Runtime\Measure\DatabaseBuild;
use PHPUnit\Framework\TestCase;

final class DatabaseBuildTest extends TestCase
{
    public function test_a_seeder_database_with_the_right_rows_is_accepted(): void
    {
        $recipe = ['customers' => 20, 'products' => 20, 'orders' => 100, 'items' => 3];

        $this->assertNull(DatabaseBuild::seederMismatch($recipe, ['customers' => 20, 'products' => 20, 'orders' => 100, 'items' => 300, 'migrations' => 4]));
    }

    public function test_the_empty_system_holds_no_orders_and_no_items(): void
    {
        $recipe = ['customers' => 20, 'products' => 20, 'orders' => 0, 'items' => 3];

        $this->assertNull(DatabaseBuild::seederMismatch($recipe, ['customers' => 20, 'products' => 20, 'orders' => 0, 'items' => 0]));
    }

    public function test_the_seeder_writes_no_orders_when_there_are_no_customers(): void
    {
        $recipe = ['customers' => 0, 'products' => 0, 'orders' => 0, 'items' => 3];

        $this->assertNull(DatabaseBuild::seederMismatch($recipe, ['customers' => 0, 'products' => 0, 'orders' => 0, 'items' => 0]));
    }

    public function test_a_table_with_the_wrong_number_of_rows_is_named(): void
    {
        $recipe = ['customers' => 20, 'products' => 20, 'orders' => 100, 'items' => 3];

        $message = DatabaseBuild::seederMismatch($recipe, ['customers' => 20, 'products' => 20, 'orders' => 99, 'items' => 300]);

        $this->assertStringContainsString('100 rows of orders', (string) $message);
        $this->assertStringContainsString('holds 99', (string) $message);
    }

    public function test_an_app_without_the_seeded_tables_is_not_the_verification_app(): void
    {
        $message = DatabaseBuild::seederMismatch(['customers' => 1, 'products' => 1, 'orders' => 1, 'items' => 1], ['users' => 3]);

        $this->assertStringContainsString('verification app', (string) $message);
    }

    public function test_a_targeted_recipe_becomes_a_scale_plan_of_that_scope(): void
    {
        $plan = DatabaseBuild::scalePlan(['mode' => 'targeted', 'n_tables' => ['orders'], 'm_table' => 'items', 'm_per' => 'orders', 'n' => 20, 'm' => 30, 'base' => 5]);

        $this->assertSame('targeted:orders', $plan->scope());
        $this->assertSame(20, $plan->rows()['orders']);
        $this->assertSame(600, $plan->rows()['items']);
    }

    public function test_a_targeted_recipe_with_no_rows_is_the_empty_system(): void
    {
        $plan = DatabaseBuild::scalePlan(['mode' => 'targeted', 'n_tables' => ['orders'], 'm_table' => null, 'm_per' => null, 'n' => 0, 'm' => 0, 'base' => 5]);

        $this->assertSame(0, $plan->rows()['orders']);
    }

    public function test_a_global_recipe_becomes_a_global_plan(): void
    {
        $plan = DatabaseBuild::scalePlan(['mode' => 'global', 'n' => 100, 'base' => 5]);

        $this->assertSame('global', $plan->scope());
        $this->assertSame(100, $plan->rowsFor('anything'));
    }

    public function test_a_recipe_without_a_mode_is_refused(): void
    {
        $this->expectException(BuildFailure::class);
        DatabaseBuild::scalePlan(['n' => 3]);
    }

    // ---- the profile's hook, run right after the generator ------------------------------------------------------

    private const MEASURE_DB = 'peak_sim_measure_abcd1234_n5';

    private static function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE marks (name TEXT)');

        return $pdo;
    }

    public function test_a_profile_without_the_hook_changes_nothing_and_says_so(): void
    {
        $pdo = self::sqlite();

        $this->assertNull(DatabaseBuild::afterGeneration([], $pdo, self::MEASURE_DB));
        $this->assertNull(DatabaseBuild::afterGeneration(['skip' => ['roles']], $pdo, self::MEASURE_DB));
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM marks')->fetchColumn());
    }

    public function test_the_hook_gets_the_connection_and_the_name_of_the_measurement_database_and_its_summary_is_kept(): void
    {
        $seen = [];
        $profile = ['after_generation' => static function (PDO $pdo, string $database) use (&$seen): array {
            $seen = [$pdo, $database];
            $pdo->exec("INSERT INTO marks (name) VALUES ('hooked')");

            return ['inserted' => 1];
        }];
        $pdo = self::sqlite();

        $summary = DatabaseBuild::afterGeneration($profile, $pdo, self::MEASURE_DB);

        $this->assertSame(['inserted' => 1], $summary);
        $this->assertSame([$pdo, self::MEASURE_DB], $seen);
        $this->assertSame('hooked', $pdo->query('SELECT name FROM marks')->fetchColumn());
    }

    public function test_a_name_that_is_not_a_measurement_database_never_reaches_the_hook(): void
    {
        $called = false;
        $profile = ['after_generation' => static function () use (&$called): array {
            $called = true;

            return [];
        }];

        foreach (['app', 'koel', 'peak_sim_measure_', 'peak_sim_measure_x', 'peak_sim_measure_ABCD1234', 'mysql'] as $name) {
            try {
                DatabaseBuild::afterGeneration($profile, self::sqlite(), $name);
                $this->fail($name . ' should have been refused');
            } catch (MeasureRefused $e) {
                $this->assertStringContainsString('not a measurement database', $e->getMessage());
            }
        }
        $this->assertFalse($called);
    }

    public function test_a_hook_that_fails_stops_the_build_instead_of_leaving_a_half_made_database(): void
    {
        $profile = ['after_generation' => static function (): array {
            throw new \RuntimeException('the generated users have no row 2');
        }];

        try {
            DatabaseBuild::afterGeneration($profile, self::sqlite(), self::MEASURE_DB);
            $this->fail('the failure should have stopped the build');
        } catch (BuildFailure $e) {
            $this->assertStringContainsString('after_generation', $e->getMessage());
            $this->assertStringContainsString('no row 2', $e->getMessage());
        }
    }

    public function test_a_refusal_inside_the_hook_is_still_a_refusal(): void
    {
        $profile = ['after_generation' => static function (): array {
            throw new MeasureRefused('refused: not a measurement database');
        }];

        $this->expectException(MeasureRefused::class);
        DatabaseBuild::afterGeneration($profile, self::sqlite(), self::MEASURE_DB);
    }

    public function test_a_hook_that_cannot_be_called_is_a_build_failure(): void
    {
        $this->expectException(BuildFailure::class);
        DatabaseBuild::afterGeneration(['after_generation' => 'no_such_function_here'], self::sqlite(), self::MEASURE_DB);
    }

    public function test_a_summary_that_is_not_a_list_of_facts_is_kept_as_an_empty_one(): void
    {
        $profile = ['after_generation' => static fn (PDO $pdo, string $database): mixed => 'done'];

        $this->assertSame([], DatabaseBuild::afterGeneration($profile, self::sqlite(), self::MEASURE_DB));
    }
}
