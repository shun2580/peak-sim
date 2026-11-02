<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Profiles;

use PDO;
use PeakSim\Runtime\Measure\Profile;
use PHPUnit\Framework\TestCase;

/**
 * The profile for one real app (Firefly III), as data: no Docker and no Firefly needed here. It is checked for shape
 * (the loader accepts it), for the declarations that are plain data, and for what its `after_generation` refuses.
 * That the declarations are true of the real schema is shown by the measurement run that uses them.
 */
final class FireflyProfileTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $profile;

    protected function setUp(): void
    {
        $this->profile = Profile::load('firefly');
    }

    public function test_the_loader_accepts_it(): void
    {
        $this->assertIsCallable($this->profile['on_boot']);
        $this->assertIsCallable($this->profile['after_generation']);
    }

    public function test_every_fixup_says_why_and_is_a_single_update(): void
    {
        foreach ($this->profile['fixups'] as $fix) {
            $this->assertNotSame('', $fix['why'], $fix['name']);
            $this->assertMatchesRegularExpression('/^UPDATE [a-z_]+ SET /', $fix['sql'], $fix['name']);
            $this->assertStringNotContainsString(';', $fix['sql'], $fix['name']);
        }
    }

    public function test_every_time_zone_column_value_is_a_real_time_zone(): void
    {
        foreach ($this->profile['values'] as $table => $columns) {
            foreach ($columns as $column => $value) {
                if (str_ends_with($column, '_tz')) {
                    $this->assertContains($value, timezone_identifiers_list(), $table . '.' . $column);
                }
            }
        }
    }

    public function test_the_two_factor_secret_is_left_empty(): void
    {
        $this->assertNull($this->profile['values']['users']['mfa_secret']);
    }

    public function test_after_generation_refuses_a_database_that_is_not_a_measurement_database(): void
    {
        $this->expectException(\Throwable::class);
        ($this->profile['after_generation'])(new PDO('sqlite::memory:'), 'production');
    }
}
