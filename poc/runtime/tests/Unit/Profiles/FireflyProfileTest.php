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
        $this->assertIsCallable($this->profile['after_generation']);
    }

    public function test_what_the_tool_now_does_for_any_app_is_no_longer_in_it(): void
    {
        // a throw-away Passport key and the empty Vite helper (Boot\AppSupport), the optional secret and the time-zone
        // columns (Data\ValueFactory), a route argument named for its table (Route\ArgumentResolver)
        $this->assertArrayNotHasKey('on_boot', $this->profile);
        $this->assertArrayNotHasKey('values', $this->profile);
        $this->assertArrayNotHasKey('transactionGroup', $this->profile['arguments']);
    }

    public function test_what_is_left_is_only_what_this_app_alone_can_say(): void
    {
        $this->assertSame(['after_generation', 'arguments', 'fixups', 'request_query'], $this->keys());
        $this->assertSame(['tagOrId', 'transactionType'], array_keys($this->profile['arguments']));
        $this->assertSame(['account_types_are_asset', 'system_currency', 'transaction_types_are_withdrawal'], $this->fixupNames());
    }

    public function test_every_remaining_entry_says_why_it_is_there(): void
    {
        foreach ($this->profile['arguments'] as $name => $how) {
            $this->assertNotSame('', $how['why'] ?? '', 'argument ' . $name);
        }
        foreach ($this->profile['fixups'] as $fix) {
            $this->assertMatchesRegularExpression('/\.php:\d+/', $fix['why'], 'fixup ' . $fix['name'] . ' names the line in the app');
        }
    }

    public function test_the_profile_stays_as_small_as_it_has_become(): void
    {
        $lines = array_filter(
            explode("\n", (string) file_get_contents(dirname(__DIR__, 3) . '/profiles/firefly.php')),
            static fn (string $l): bool => preg_match('~^\s*($|//|\*|/\*)~', $l) !== 1,
        );

        // it was 117 effective lines before the generic handling; a profile that grows back says the tool lost something
        $this->assertLessThanOrEqual(60, count($lines));
    }

    /** @return list<string> */
    private function keys(): array
    {
        $keys = array_keys($this->profile);
        sort($keys);

        return $keys;
    }

    /** @return list<string> */
    private function fixupNames(): array
    {
        $names = array_map(static fn (array $f): string => $f['name'], $this->profile['fixups']);
        sort($names);

        return $names;
    }

    public function test_every_fixup_says_why_and_is_a_single_update(): void
    {
        foreach ($this->profile['fixups'] as $fix) {
            $this->assertNotSame('', $fix['why'], $fix['name']);
            $this->assertMatchesRegularExpression('/^UPDATE [a-z_]+ SET /', $fix['sql'], $fix['name']);
            $this->assertStringNotContainsString(';', $fix['sql'], $fix['name']);
        }
    }

    public function test_after_generation_refuses_a_database_that_is_not_a_measurement_database(): void
    {
        $this->expectException(\Throwable::class);
        ($this->profile['after_generation'])(new PDO('sqlite::memory:'), 'production');
    }
}
