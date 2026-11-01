<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Profiles;

use PDO;
use PeakSim\Runtime\Measure\Profile;
use PHPUnit\Framework\TestCase;

/**
 * The administrator variant of the first Koel profile: the same knowledge, except that the first request of each route
 * is made as the administrator. It is data, so no Docker and no Koel are needed here.
 */
final class KoelAdminProfileTest extends TestCase
{
    private const NAME = 'koel_admin';

    private const FILE = __DIR__ . '/../../../profiles/koel_admin.php';

    /** @return array<string, mixed> */
    private static function profile(): array
    {
        return Profile::load(self::NAME);
    }

    /** @return array<string, mixed> */
    private static function base(): array
    {
        return Profile::load('koel');
    }

    public function test_the_loader_accepts_it_by_name(): void
    {
        $this->assertFileExists(self::FILE);
        $this->assertNotSame([], self::profile());
    }

    public function test_the_first_request_is_made_as_the_administrator_and_the_first_profile_says_user(): void
    {
        $this->assertSame('admin', self::profile()['login_as']);
        $this->assertSame('user', self::base()['login_as']);
    }

    public function test_everything_else_is_what_the_first_profile_says(): void
    {
        $base = self::base();
        $p = self::profile();
        unset($base['login_as'], $p['login_as']);

        $this->assertSame(array_keys($base), array_keys($p));
        foreach (array_keys($base) as $key) {
            if ($key === 'after_generation') {
                continue; // a closure: the same one is shared, checked below
            }
            $this->assertSame($base[$key], $p[$key], $key);
        }
    }

    public function test_it_refuses_a_database_that_is_not_a_measurement_database_as_the_first_profile_does(): void
    {
        $after = self::profile()['after_generation'];
        $this->assertInstanceOf(\Closure::class, $after);
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/measurement database/i');
        $after($pdo, 'koel');
    }

    public function test_the_administrator_the_variant_logs_in_as_is_one_the_first_profile_defines(): void
    {
        $p = self::profile();

        $this->assertArrayHasKey('admin', $p);
        $this->assertNotSame([], $p['admin']);
    }

    public function test_the_file_says_what_it_changes_and_why_the_default_is_not_the_administrator(): void
    {
        $source = (string) file_get_contents(self::FILE);
        $head = substr($source, 0, (int) strpos($source, '$profile = '));

        $this->assertMatchesRegularExpression('/knowledge about (the|this) app/i', $head);
        $this->assertStringContainsString('koel_admin', $head);
        $this->assertMatchesRegularExpression('/rows (that )?(a|the)[\s\/]+login[\s\/]+can see|visible rows/i', $head);
        $this->assertSame(0, preg_match('~/home/|/Users/|/mnt/~', $source), 'no absolute path');
    }
}
