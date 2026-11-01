<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Docker\Data;

use PDO;
use PeakSim\Runtime\Config\MeasureRefused;
use PeakSim\Runtime\Measure\DatabaseBuild;
use PeakSim\Runtime\Measure\MeasureExit;
use PeakSim\Runtime\Tests\Docker\Data\Support\GenerationCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The wiring of a profile's `after_generation` hook into the database build, on the verification app and a real MySQL:
 * the hook is called once, after the generator, on the measurement database; a hook that fails stops the build; a
 * connection that is not on the measurement database is refused. (A real app's profile is checked on that app.)
 */
#[Group('in-stack')]
final class ProfileHookDockerTest extends GenerationCase
{
    private const SCALE = 100;

    public static function tearDownAfterClass(): void
    {
        self::drop(self::SCALE);
    }

    /** @return array{int, array<string, mixed>, string} [exit, what was built, stderr] */
    private static function build(?string $profile): array
    {
        [$exit, $out, $err] = self::php(__DIR__ . '/Support/build_with_profile.php', array_filter([(string) self::SCALE, $profile]));
        $lines = array_values(array_filter(array_map('trim', explode("\n", $out)), static fn (string $l): bool => $l !== '' && $l[0] === '{'));

        return [$exit, $lines === [] ? [] : json_decode($lines[array_key_last($lines)], true, 512, JSON_THROW_ON_ERROR), $err];
    }

    public function testTheHookRunsOnceAfterTheGeneratorOnTheMeasurementDatabase(): void
    {
        [$exit, $info, $err] = self::build('hooked');

        self::assertSame(0, $exit, $err);
        self::assertSame(self::config()->dbNameForScale(self::SCALE), $info['database']);
        // The generator had already written the customers when the hook looked (5 at N=5), and the hook's own row came after.
        self::assertSame(['customers_seen' => 5, 'connected_to_the_named_database' => true], $info['after_generation']);
        $pdo = self::pdo(self::SCALE);
        self::assertSame(6, self::rowCount($pdo, 'customers'));
        self::assertSame(1, (int) self::scalar($pdo, "SELECT COUNT(*) FROM customers WHERE email = 'hook@measure.invalid'"));
        self::assertSame(6, $info['rows']['customers'], 'the row counts of the record are read after the hook');
    }

    public function testWithoutAProfileNothingIsAddedAndNothingIsReported(): void
    {
        [$exit, $info, $err] = self::build(null);

        self::assertSame(0, $exit, $err);
        self::assertArrayNotHasKey('after_generation', $info);
        self::assertSame(5, self::rowCount(self::pdo(self::SCALE), 'customers'));
    }

    public function testAHookThatFailsStopsTheBuildWithItsMessage(): void
    {
        [$exit, , $err] = self::build('failing');

        self::assertSame(MeasureExit::DATA, $exit);
        self::assertStringContainsString('after_generation failed', $err);
        self::assertStringContainsString('no row 2', $err);
    }

    public function testAConnectionThatIsNotOnTheMeasurementDatabaseIsRefusedBeforeTheHookRuns(): void
    {
        self::fresh(self::SCALE);
        $config = self::config();
        $noDatabase = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $config->dbHost, $config->dbPort), $config->dbUser, $config->dbPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        $called = false;
        $profile = ['after_generation' => static function () use (&$called): array {
            $called = true;

            return [];
        }];

        try {
            DatabaseBuild::afterGeneration($profile, $noDatabase, $config->dbNameForScale(self::SCALE));
            self::fail('a connection with no database selected should have been refused');
        } catch (MeasureRefused $e) {
            self::assertStringContainsString('not on the measurement database', $e->getMessage());
        }
        self::assertFalse($called);

        $this->expectException(MeasureRefused::class);
        DatabaseBuild::afterGeneration($profile, self::pdo(self::SCALE), 'app');
    }
}
