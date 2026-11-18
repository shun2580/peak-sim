<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Measure;

use PeakSim\Runtime\Explain\StatsDb;
use PeakSim\Runtime\Explain\StatsStatements;
use PeakSim\Runtime\Measure\BuildFailure;
use PeakSim\Runtime\Measure\Options;
use PeakSim\Runtime\Measure\Profile;
use PeakSim\Runtime\Measure\RebuildStep;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The end of a build for an app whose profile has `rebuild` steps: build, rebuild, ANALYZE, in that order, a rebuild that fails
 * stops the build, and the rows around the rebuild are kept in the record. Nothing here touches a database or an app: the artisan
 * call, the row count and the statistics statements are made-up doors that write into one shared log.
 */
final class RebuildStepTest extends TestCase
{
    private const STEP = ['name' => 'regenerate_references', 'command' => 'bookstack:regenerate-references', 'tables' => ['references'], 'why' => 'x'];

    /** @param list<string> $log */
    private static function stats(array &$log, bool $analyzeError = false): StatsStatements
    {
        return new StatsStatements(new class($log, $analyzeError) implements StatsDb {
            private int $reads = 0;

            /** @param list<string> $log */
            public function __construct(private array &$log, private readonly bool $analyzeError)
            {
            }

            public function run(string $sql): array
            {
                if (str_starts_with($sql, 'ANALYZE TABLE')) {
                    $this->log[] = $sql;

                    return [['Msg_type' => $this->analyzeError ? 'Error' : 'status', 'Msg_text' => $this->analyzeError ? 'broken' : 'OK']];
                }
                if (str_contains($sql, 'table_rows AS n')) {
                    $this->reads++;

                    return $this->reads === 1 ? [['t' => 'references', 'n' => 0], ['t' => 'entities', 'n' => 90]] : [['t' => 'references', 'n' => 40], ['t' => 'entities', 'n' => 90]];
                }
                if (str_contains($sql, 'information_schema.TABLES')) {
                    return [['t' => 'references'], ['t' => 'entities']];
                }

                return [];
            }
        });
    }

    /**
     * @param list<string>                 $log
     * @param list<int>                    $counts the rows each count returns, in order (the step counts twice: before and after)
     * @param \Closure(string): array{0: int, 1: string}|null $artisan
     *
     * @return array<string, mixed>|null
     */
    private static function finish(array $profile, array &$log, array $counts = [10, 25], ?\Closure $artisan = null, bool $analyzeError = false): ?array
    {
        $artisan ??= static function (string $command) use (&$log): array {
            $log[] = 'artisan ' . $command;

            return [0, 'References have been regenerated'];
        };
        $time = 100.0;

        return RebuildStep::run(
            $profile,
            $artisan,
            static function (string $table) use (&$log, &$counts): int {
                $log[] = 'count ' . $table;

                return array_shift($counts) ?? 0;
            },
            self::stats($log, $analyzeError),
            static function () use (&$time): float {
                $time += 1.5;

                return $time;
            },
        );
    }

    public function test_the_rebuild_runs_between_the_counts_and_ANALYZE_runs_after_it(): void
    {
        $log = [];

        self::finish(['rebuild' => [self::STEP]], $log);

        $this->assertSame([
            'count references',
            'artisan bookstack:regenerate-references',
            'count references',
            'ANALYZE TABLE `references`',
            'ANALYZE TABLE `entities`',
        ], $log, 'the rebuild comes before every ANALYZE TABLE, and nothing is analysed before it');
    }

    public function test_the_rows_of_the_rebuilt_table_before_and_after_are_kept_with_the_order_and_the_analyze_record(): void
    {
        $log = [];

        $record = self::finish(['rebuild' => [self::STEP]], $log, [10, 25]);

        $this->assertSame(['build', 'rebuild', 'analyze'], $record['order']);
        $this->assertCount(1, $record['steps']);
        $this->assertSame('regenerate_references', $record['steps'][0]['name']);
        $this->assertSame('bookstack:regenerate-references', $record['steps'][0]['command']);
        $this->assertSame(['references' => 10], $record['steps'][0]['rows_before']);
        $this->assertSame(['references' => 25], $record['steps'][0]['rows_after']);
        $this->assertSame(1.5, $record['steps'][0]['seconds']);
        $this->assertSame(2, $record['analyze']['tables']);
        $this->assertSame(['references' => ['before' => 0, 'after' => 40]], $record['analyze']['table_rows_changed'], 'only the table whose figure changed');
    }

    public function test_a_rebuild_that_exits_with_an_error_stops_the_build_and_nothing_is_analysed(): void
    {
        $log = [];

        try {
            self::finish(['rebuild' => [self::STEP]], $log, [10, 25], static function (string $command) use (&$log): array {
                $log[] = 'artisan ' . $command;

                return [1, "Illuminate\\Database\\QueryException: something\nbroke"];
            });
            $this->fail('the failed rebuild was passed over');
        } catch (BuildFailure $e) {
            $this->assertStringContainsString('regenerate_references', $e->getMessage());
            $this->assertStringContainsString('exit 1', $e->getMessage());
            $this->assertStringContainsString('something broke', $e->getMessage());
        }
        $this->assertSame(['count references', 'artisan bookstack:regenerate-references'], $log, 'no count after, no ANALYZE');
    }

    public function test_a_command_that_throws_or_does_not_exist_stops_the_build_too(): void
    {
        $log = [];

        try {
            self::finish(['rebuild' => [self::STEP]], $log, [10, 25], static function (string $command) use (&$log): array {
                $log[] = 'artisan ' . $command;

                throw new \RuntimeException('Command "' . $command . '" is not defined.');
            });
            $this->fail('the missing command was passed over');
        } catch (BuildFailure $e) {
            $this->assertStringContainsString('is not defined', $e->getMessage());
        }
        $this->assertSame([], array_values(array_filter($log, static fn (string $l): bool => str_starts_with($l, 'ANALYZE'))));
    }

    public function test_when_the_first_step_fails_the_second_does_not_run(): void
    {
        $log = [];
        $second = ['name' => 'second', 'command' => 'app:second', 'tables' => ['entities'], 'why' => 'x'];

        try {
            self::finish(['rebuild' => [self::STEP, $second]], $log, [1, 2, 3, 4], static function (string $command) use (&$log): array {
                $log[] = 'artisan ' . $command;

                return [2, 'no'];
            });
            $this->fail('the failed rebuild was passed over');
        } catch (BuildFailure) {
        }
        $this->assertSame(['count references', 'artisan bookstack:regenerate-references'], $log);
    }

    public function test_the_steps_run_in_the_order_of_the_profile_and_each_keeps_its_own_counts(): void
    {
        $log = [];
        $second = ['name' => 'second', 'command' => 'app:second', 'tables' => ['entities'], 'why' => 'x'];

        $record = self::finish(['rebuild' => [self::STEP, $second]], $log, [1, 2, 3, 4]);

        $this->assertSame(['count references', 'artisan bookstack:regenerate-references', 'count references', 'count entities', 'artisan app:second', 'count entities'], array_slice($log, 0, 6));
        $this->assertSame(['references' => 1], $record['steps'][0]['rows_before']);
        $this->assertSame(['references' => 2], $record['steps'][0]['rows_after']);
        $this->assertSame(['entities' => 3], $record['steps'][1]['rows_before']);
        $this->assertSame(['entities' => 4], $record['steps'][1]['rows_after']);
    }

    public function test_an_error_message_of_ANALYZE_TABLE_fails_the_build(): void
    {
        $log = [];

        $this->expectException(BuildFailure::class);
        $this->expectExceptionMessage('ANALYZE TABLE reported an error');
        self::finish(['rebuild' => [self::STEP]], $log, [10, 25], null, true);
    }

    public function test_a_table_that_cannot_be_counted_fails_the_build_before_the_rebuild_runs(): void
    {
        $log = [];
        $profile = ['rebuild' => [self::STEP]];

        try {
            RebuildStep::run(
                $profile,
                static function (string $command) use (&$log): array {
                    $log[] = 'artisan ' . $command;

                    return [0, ''];
                },
                static fn (string $table): int => throw new \RuntimeException('no such table'),
                self::stats($log),
            );
            $this->fail('the failed count was passed over');
        } catch (BuildFailure $e) {
            $this->assertStringContainsString('could not be counted', $e->getMessage());
        }
        $this->assertSame([], $log);
    }

    public function test_a_profile_without_a_rebuild_gets_nothing_not_even_ANALYZE(): void
    {
        $log = [];

        $this->assertNull(self::finish([], $log));
        $this->assertNull(self::finish(['fixups' => []], $log));
        $this->assertSame([], $log);
    }

    public function test_the_bookstack_profile_declares_the_rebuild_the_maintenance_page_runs(): void
    {
        $profile = Profile::load('bookstack');

        $this->assertCount(1, $profile['rebuild']);
        $this->assertSame('bookstack:regenerate-references', $profile['rebuild'][0]['command']);
        $this->assertSame(['references'], $profile['rebuild'][0]['tables']);
    }

    /** @return iterable<string, array{string}> */
    public static function profilesWithoutARebuild(): iterable
    {
        yield 'the verification app' => ['fixtures'];
        yield 'Firefly III' => ['firefly'];
        yield 'Koel' => ['koel'];
    }

    #[DataProvider('profilesWithoutARebuild')]
    public function test_the_other_profiles_have_no_rebuild_so_their_builds_are_as_they_were(string $name): void
    {
        $this->assertArrayNotHasKey('rebuild', Profile::load($name));
    }

    /** @return iterable<string, array{string}> */
    public static function badRebuilds(): iterable
    {
        yield 'not a list' => ["'rebuild' => 'x'"];
        yield 'empty' => ["'rebuild' => []"];
        yield 'a step that is not facts' => ["'rebuild' => ['x']"];
        yield 'no name' => ["'rebuild' => [['command' => 'a:b', 'tables' => ['t'], 'why' => 'w']]"];
        yield 'a command with arguments' => ["'rebuild' => [['name' => 'n', 'command' => 'a:b --force', 'tables' => ['t'], 'why' => 'w']]"];
        yield 'a command with a shell word' => ["'rebuild' => [['name' => 'n', 'command' => 'a:b;ls', 'tables' => ['t'], 'why' => 'w']]"];
        yield 'no tables' => ["'rebuild' => [['name' => 'n', 'command' => 'a:b', 'tables' => [], 'why' => 'w']]"];
        yield 'a table that is not a plain name' => ["'rebuild' => [['name' => 'n', 'command' => 'a:b', 'tables' => ['t`; drop'], 'why' => 'w']]"];
        yield 'no why' => ["'rebuild' => [['name' => 'n', 'command' => 'a:b', 'tables' => ['t']]]"];
    }

    #[DataProvider('badRebuilds')]
    public function test_a_badly_formed_rebuild_is_refused_when_the_profile_is_loaded(string $entry): void
    {
        $dir = sys_get_temp_dir() . '/peak-rebuild-test-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/bad.php', '<?php return [' . $entry . '];');

        try {
            Profile::load('bad', $dir);
            $this->fail('the badly formed rebuild was accepted');
        } catch (BuildFailure $e) {
            $this->assertStringContainsString('rebuild', $e->getMessage());
        } finally {
            unlink($dir . '/bad.php');
            rmdir($dir);
        }
    }

    public function test_a_capture_of_a_profile_with_a_rebuild_must_ask_for_ANALYZE_and_other_runs_are_not_stopped(): void
    {
        $withRebuild = ['rebuild' => [self::STEP]];
        $capture = ['--tap-dir=/out/capture-taps', '--profile=bookstack'];

        $this->assertNotNull(Options::parse($capture)->refusalForProfile($withRebuild), 'a capture with ANALYZE off');
        $this->assertNull(Options::parse([...$capture, '--analyze-after-build=on'])->refusalForProfile($withRebuild), 'a capture with ANALYZE on');
        $this->assertNull(Options::parse(['--profile=bookstack'])->refusalForProfile($withRebuild), 'a measurement (no tap directory)');
        $this->assertNull(Options::parse(['--tap-dir=/out/capture-taps', '--profile=koel'])->refusalForProfile([]), 'a profile with no rebuild');
    }

    public function test_the_build_of_a_database_runs_the_rebuild_after_the_generator_and_never_in_the_seeder_mode(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Measure/DatabaseBuild.php');

        $generate = strpos($source, '(new Generator())->generate(');
        $hook = strpos($source, 'self::afterGeneration($profile');
        $rebuild = strpos($source, 'self::rebuild($profile');
        $this->assertNotFalse($generate);
        $this->assertNotFalse($hook);
        $this->assertNotFalse($rebuild);
        $this->assertTrue($generate < $hook && $hook < $rebuild, 'generate, then the profile\'s fixups, then the rebuild');
        $this->assertStringContainsString("if (\$mode !== 'seeder') {\n            \$rebuilt = self::rebuild(", $source);
        $this->assertStringContainsString("\$info['rebuild'] = \$rebuilt;", $source, 'what the rebuild did is in the record of the build');
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function callersOfTheBuilder(): iterable
    {
        // each: the file that starts bin/inner/build-db.php, what it must say to start it, and what hands the profile to it
        yield 'the lab\'s build-db' => ['poc/lab/bin/build-db', 'inner/build-db.php', '--profile=bookstack'];
        yield 'bin/measure and bin/capture (the driver)' => ['poc/runtime/bin/inner/measure.php', "inner/build-db.php', \$env, \$options->data, ProcessDatabaseBuilder::TIMEOUT, PHP_BINARY, \$options->profile", '$options->profile'];
        yield 'the builder process' => ['poc/runtime/src/Measure/ProcessDatabaseBuilder.php', "'--profile=' . \$this->profile", '--profile='];
        yield 'bin/variance-run' => ['poc/runtime/bin/variance-run', 'inner/build-db.php', 'profile_arg'];
        yield 'bin/explain-at-scale' => ['poc/runtime/bin/explain-at-scale', 'inner/build-db.php', 'plan_args+=("--profile=$profile")'];
    }

    /** Every path that makes a measurement database starts bin/inner/build-db.php with the profile, where the rebuild and ANALYZE are: none has its own build. */
    #[DataProvider('callersOfTheBuilder')]
    public function test_every_way_of_building_a_database_goes_through_the_builder_that_rebuilds_and_hands_it_the_profile(string $file, string $startsBuilder, string $handsProfile): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 5) . '/' . $file);

        $this->assertStringContainsString($startsBuilder, $source);
        $this->assertStringContainsString($handsProfile, $source);
    }

    public function test_the_output_note_tells_the_reader_the_databases_must_be_in_the_same_maintenance_state(): void
    {
        $note = \PeakSim\Runtime\Simulate\Predict\PredictionReport::ESTIMATE_BASIS_NOTE;

        $this->assertStringContainsString('same maintenance state', $note);
        $this->assertStringContainsString('ANALYZE TABLE', $note);
        $this->assertStringContainsString('captured on', $note);
    }
}
