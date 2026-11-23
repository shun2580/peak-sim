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

    public function test_on_boot_runs_on_the_application_it_is_given(): void
    {
        $this->write('boot', "<?php return ['on_boot' => static function (object \$app): void { \$app->booted = true; }];");

        $app = new \stdClass();
        Profile::onBoot(Profile::load('boot', $this->dir), $app);

        $this->assertTrue($app->booted);
    }

    public function test_a_profile_without_on_boot_leaves_the_application_alone(): void
    {
        $app = new \stdClass();
        Profile::onBoot([], $app);

        $this->assertSame([], get_object_vars($app));
    }

    public function test_on_boot_that_is_not_a_function_is_refused(): void
    {
        $this->write('badboot', "<?php return ['on_boot' => 'not a function'];");

        $this->expectException(BuildFailure::class);
        $this->expectExceptionMessage('on_boot must be a function');
        Profile::load('badboot', $this->dir);
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

    public function test_an_unfillable_entry_that_names_routes_is_checked_when_the_profile_is_loaded(): void
    {
        $this->write('routed', "<?php return ['unfillable' => ['a' => ['routes' => ['uri_prefix' => ['rest/'], 'middleware' => ['signed'], 'action_prefix' => ['App\\\\X']], 'reason' => 'unauthenticated', 'why' => 'x']]];");

        $this->assertArrayHasKey('a', Profile::load('routed', $this->dir)['unfillable']);
    }

    public function test_every_reason_the_koel_profile_uses_is_in_the_vocabulary_so_the_real_profile_loads(): void
    {
        $reasons = array_column(Profile::load('koel')['unfillable'], 'reason');

        $this->assertNotSame([], $reasons);
        $this->assertContains('file_dependency', $reasons);
    }

    /** @return iterable<string, array{string}> */
    public static function badEntries(): iterable
    {
        yield 'an unknown route key' => ["['routes' => ['url' => ['rest/']], 'reason' => 'unauthenticated', 'why' => 'x']"];
        yield 'a route key that is not a list of strings' => ["['routes' => ['uri_prefix' => 'rest/'], 'reason' => 'unauthenticated', 'why' => 'x']"];
        yield 'an empty list' => ["['routes' => ['uri_prefix' => []], 'reason' => 'unauthenticated', 'why' => 'x']"];
        yield 'a reason outside the vocabulary' => ["['routes' => ['uri_prefix' => ['rest/']], 'reason' => 'because', 'why' => 'x']"];
    }

    #[DataProvider('badEntries')]
    public function test_a_badly_formed_unfillable_entry_is_refused_at_load(string $entry): void
    {
        $this->write('badentry', "<?php return ['unfillable' => ['a' => $entry]];");

        $this->expectException(BuildFailure::class);
        $this->expectExceptionMessage('unfillable');
        Profile::load('badentry', $this->dir);
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

    // ---- request_query -----------------------------------------------------------------------------------------

    public function test_a_request_query_entry_is_checked_when_the_profile_is_loaded(): void
    {
        $this->write('queried', "<?php return ['user' => ['id' => 1, 'email' => 'u@x.invalid'], 'admin' => ['id' => 2, 'email' => 'a@x.invalid'], "
            . "'request_query' => ['s' => ['routes' => ['uri_prefix' => ['rest/']], 'as' => 'admin', 'query' => ['u' => '{email}', 'apiKey' => '{key:subsonic}']]]];");

        $this->assertArrayHasKey('s', Profile::load('queried', $this->dir)['request_query']);
    }

    /** @return iterable<string, array{string}> */
    public static function badQueries(): iterable
    {
        yield 'no routes' => ["['query' => ['u' => 'x']]"];
        yield 'an unknown route key' => ["['routes' => ['url' => ['rest/']], 'query' => ['u' => 'x']]"];
        yield 'no query' => ["['routes' => ['uri_prefix' => ['rest/']]]"];
        yield 'an empty query' => ["['routes' => ['uri_prefix' => ['rest/']], 'query' => []]"];
        yield 'a name that is not a plain word' => ["['routes' => ['uri_prefix' => ['rest/']], 'query' => ['a b' => 'x']]"];
        yield 'a value that is not text' => ["['routes' => ['uri_prefix' => ['rest/']], 'query' => ['u' => 3]]"];
        yield 'a placeholder nothing reads' => ["['routes' => ['uri_prefix' => ['rest/']], 'query' => ['u' => '{password}']]"];
        yield 'a login that is neither the user nor the admin' => ["['routes' => ['uri_prefix' => ['rest/']], 'as' => 'root', 'query' => ['u' => 'x']]"];
        yield 'a login the profile does not have' => ["['routes' => ['uri_prefix' => ['rest/']], 'as' => 'manager', 'query' => ['u' => 'x']]"];
        yield 'an email wanted of a login that has none' => ["['routes' => ['uri_prefix' => ['rest/']], 'as' => 'user', 'query' => ['u' => '{email}']]"];
    }

    #[DataProvider('badQueries')]
    public function test_a_badly_formed_request_query_entry_is_refused_at_load(string $entry): void
    {
        $this->write('badquery', "<?php return ['user' => ['id' => 1], 'request_query' => ['a' => $entry]];");

        $this->expectException(BuildFailure::class);
        $this->expectExceptionMessage('request_query');
        Profile::load('badquery', $this->dir);
    }

    public function test_a_request_query_with_no_login_at_all_is_refused_when_it_asks_for_the_email(): void
    {
        $this->write('nologin', "<?php return ['request_query' => ['a' => ['routes' => ['uri_prefix' => ['rest/']], 'query' => ['u' => '{email}']]]];");

        $this->expectException(BuildFailure::class);
        $this->expectExceptionMessage('request_query');
        Profile::load('nologin', $this->dir);
    }

    // ---- auth_gates --------------------------------------------------------------------------------------------

    public function test_an_auth_gates_entry_is_checked_when_the_profile_is_loaded(): void
    {
        $this->write('gated', "<?php return ['user' => ['id' => 1], 'admin' => ['id' => 2], "
            . "'auth_gates' => ['a' => ['middleware' => 'Gate'], 'b' => ['middleware' => ['Vendor\\\\Door', 'Other'], 'guard' => 'staff', 'as' => 'admin']]];");

        $this->assertSame(['a', 'b'], array_keys(Profile::load('gated', $this->dir)['auth_gates']));
    }

    /** @return iterable<string, array{string}> */
    public static function badGates(): iterable
    {
        yield 'not an entry' => ["'Gate'"];
        yield 'no middleware' => ["['guard' => 'staff']"];
        yield 'an empty list of middleware' => ["['middleware' => []]"];
        yield 'a middleware that is not text' => ["['middleware' => [3]]"];
        yield 'a middleware that is not a plain name' => ["['middleware' => ['a b']]"];
        yield 'a guard that is not a plain word' => ["['middleware' => 'Gate', 'guard' => 'a b']"];
        yield 'an as that is neither user nor admin' => ["['middleware' => 'Gate', 'as' => 'root']"];
        yield 'an admin the profile does not have' => ["['middleware' => 'Gate', 'as' => 'admin']"];
        yield 'a key nothing reads' => ["['middleware' => 'Gate', 'routes' => ['uri_prefix' => ['x']]]"];
    }

    #[DataProvider('badGates')]
    public function test_a_badly_formed_auth_gates_entry_is_refused_at_load(string $entry): void
    {
        $this->write('badgate', "<?php return ['user' => ['id' => 1], 'auth_gates' => ['a' => $entry]];");

        $this->expectException(BuildFailure::class);
        $this->expectExceptionMessage('auth_gates');
        Profile::load('badgate', $this->dir);
    }
}
