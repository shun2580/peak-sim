<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Docker;

use PeakSim\Runtime\Boot\Fakes;
use PeakSim\Runtime\Tests\Docker\Support\DockerCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The two conditions a profile's `on_boot` is held to (it does not touch the database, it does not take the fakes
 * off), on a real Laravel in the sealed container. `Boot\OnBootWatch` is tested with stand-ins in the unit suite;
 * `Fakes::fingerprint` reads real facades and can only be tried here. The function is handed to `Profile::onBoot`, the
 * way a run does, on the verification app with its fakes in place.
 */
#[Group('in-stack')]
final class OnBootDockerTest extends DockerCase
{
    private const SCALE = 100;

    public static function setUpBeforeClass(): void
    {
        self::requireInsideStack();
        self::prepare(self::SCALE, [5, 5, 5, 2]);
    }

    public static function tearDownAfterClass(): void
    {
        self::drop(self::SCALE);
    }

    /** @return array<string, mixed> */
    private static function runCase(string $case): array
    {
        [$exit, $out, $err] = self::php(__DIR__ . '/Support/on_boot.php', [(string) self::SCALE, $case]);
        self::assertSame(0, $exit, $err);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $out)), static fn (string $l): bool => $l !== '' && $l[0] === '{'));
        self::assertNotSame([], $lines, 'the script printed no result: ' . $out . $err);

        return json_decode($lines[array_key_last($lines)], true, 512, JSON_THROW_ON_ERROR);
    }

    public function testAnOnBootThatOnlySetsConfigurationIsLetThroughAndTheFakesAreSeenAsUnchanged(): void
    {
        $result = self::runCase('config_only');

        self::assertSame('passed', $result['outcome'], 'a real app must not trip the watch on a setting: ' . json_encode($result));
        self::assertSame('set by on_boot', $result['config_value'], 'the function ran');
        self::assertSame(Fakes::fingerprintKeys(), self::sorted($result['fingerprint_keys']), 'the fingerprint reads what the watch compares');
    }

    #[DataProvider('touchesTheDatabase')]
    public function testAnOnBootThatTouchesTheDatabaseIsStopped(string $case): void
    {
        $result = self::runCase($case);

        self::assertSame('stopped', $result['outcome'], json_encode($result));
        self::assertStringContainsString('touched the database', $result['message']);
        self::assertMatchesRegularExpression('/^peak_sim_measure_[a-z0-9_]{8,47}$/', (string) $result['database'], 'the run is on a measurement database');
    }

    /** @return array<string, array{string}> */
    public static function touchesTheDatabase(): array
    {
        return ['a statement' => ['query'], 'a transaction left open' => ['transaction']];
    }

    #[DataProvider('takesAFakeOff')]
    public function testAnOnBootThatTakesAFakeOffIsStopped(string $case, string $fake): void
    {
        $result = self::runCase($case);

        self::assertSame('stopped', $result['outcome'], json_encode($result));
        self::assertStringContainsString('changed a fake', $result['message']);
        self::assertStringContainsString($fake, $result['message'], 'the message names the fake that was taken off');
    }

    /** @return array<string, array{string, string}> */
    public static function takesAFakeOff(): array
    {
        return [
            'the mailer' => ['mail_swap', 'mail'],
            'the queue' => ['queue_swap', 'queue'],
            'the block on stray HTTP requests' => ['http_swap', 'http_stray_blocked'],
        ];
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private static function sorted(array $names): array
    {
        sort($names);

        return $names;
    }
}
