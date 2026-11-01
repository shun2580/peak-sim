<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Measure;

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
}
