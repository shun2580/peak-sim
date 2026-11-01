<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Measure;

use PDO;
use PeakSim\Runtime\Data\Generator;
use PeakSim\Runtime\Data\ScalePlan;
use PeakSim\Runtime\Measure\BuildFailure;
use PeakSim\Runtime\Measure\Profile;
use PeakSim\Runtime\Tests\Unit\Data\RecordingSink;
use PeakSim\Runtime\Tests\Unit\Data\Schemas;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A profile is the app-specific knowledge handed to the generator: a named file under profiles/, nothing else. */
final class ProfileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/peak-profile-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function write(string $name, string $body): void
    {
        file_put_contents($this->dir . '/' . $name . '.php', $body);
    }

    public function test_a_named_profile_is_the_array_its_file_returns(): void
    {
        $this->write('shop', "<?php return ['values' => ['orders' => ['status' => 'paid']], 'rows' => ['items' => 7]];");

        $this->assertSame(['values' => ['orders' => ['status' => 'paid']], 'rows' => ['items' => 7]], Profile::load('shop', $this->dir));
    }

    public function test_no_profile_is_the_empty_profile(): void
    {
        $this->assertSame([], Profile::load(null, $this->dir));
    }

    public function test_a_profile_that_does_not_exist_is_refused_and_named(): void
    {
        $this->expectException(BuildFailure::class);
        $this->expectExceptionMessage('ghost');
        Profile::load('ghost', $this->dir);
    }

    /** @return iterable<string, array{string}> */
    public static function badNames(): iterable
    {
        yield 'parent directory' => ['../x'];
        yield 'slash' => ['a/b'];
        yield 'extension' => ['a.php'];
        yield 'capitals' => ['Shop'];
        yield 'empty' => [''];
        yield 'space' => ['a b'];
    }

    #[DataProvider('badNames')]
    public function test_a_name_that_could_leave_the_directory_is_refused(string $name): void
    {
        $this->expectException(BuildFailure::class);
        Profile::load($name, $this->dir);
    }

    public function test_a_profile_that_does_not_return_an_array_is_refused(): void
    {
        $this->write('bad', '<?php return 3;');

        $this->expectException(BuildFailure::class);
        Profile::load('bad', $this->dir);
    }

    public function test_a_key_the_generator_does_not_know_is_refused(): void
    {
        $this->write('typo', "<?php return ['value' => []];");

        $this->expectException(BuildFailure::class);
        $this->expectExceptionMessage('value');
        Profile::load('typo', $this->dir);
    }

    public function test_the_keys_a_profile_with_logins_reference_rows_and_a_hook_may_carry_are_all_read(): void
    {
        $this->write('full', "<?php return ['skip' => [], 'rows' => [], 'values' => [], 'user' => ['id' => 1], 'admin' => ['id' => 2], 'login_as' => 'user', "
            . "'reference_rows' => [], 'role_permissions' => [], 'fixups' => [], 'arguments' => [], 'unfillable' => [], "
            . "'after_generation' => static fn (PDO \$pdo, string \$database): array => []];");

        $profile = Profile::load('full', $this->dir);

        $this->assertSame(['skip', 'rows', 'values', 'user', 'admin', 'login_as', 'reference_rows', 'role_permissions', 'fixups', 'arguments', 'unfillable', 'after_generation'], array_keys($profile));
    }

    public function test_a_hook_that_cannot_be_called_is_refused_at_load_not_in_the_middle_of_a_build(): void
    {
        $this->write('hookless', "<?php return ['after_generation' => 'not a function'];");

        $this->expectException(BuildFailure::class);
        $this->expectExceptionMessage('after_generation');
        Profile::load('hookless', $this->dir);
    }

    public function test_login_as_names_the_user_or_the_admin_and_the_admin_needs_a_row_to_log_in_as(): void
    {
        $this->write('nobody', "<?php return ['user' => ['id' => 1], 'login_as' => 'admin'];");
        $this->write('stranger', "<?php return ['user' => ['id' => 1], 'login_as' => 'root'];");

        foreach (['nobody', 'stranger'] as $name) {
            try {
                Profile::load($name, $this->dir);
                $this->fail($name . ' should have been refused');
            } catch (BuildFailure $e) {
                $this->assertStringContainsString('login_as', $e->getMessage());
            }
        }
    }

    public function test_the_profile_for_the_verification_app_gives_every_order_the_status_the_filter_reads(): void
    {
        $profile = Profile::load('fixtures');
        $this->assertSame(['values'], array_keys($profile), 'only values: no skipped tables, no row counts that tune the data');

        $schema = Schemas::shop();
        $sink = new RecordingSink();
        (new Generator())->generateInto($schema, $sink, ScalePlan::targeted(['orders'], null, null, 4, 0, 2), $profile);

        $statuses = array_unique(array_column($sink->assoc('orders'), 'status'));
        $this->assertSame(['paid'], $statuses);
    }
}
