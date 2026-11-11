<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Measure;

use PeakSim\Runtime\Explain\StatsDb;
use PeakSim\Runtime\Explain\StatsStatements;
use PeakSim\Runtime\Measure\AnalyzingDatabaseBuilder;
use PeakSim\Runtime\Measure\BuildFailure;
use PeakSim\Runtime\Measure\DatabaseBuilder;
use PeakSim\Runtime\Measure\DatabasePoint;
use PHPUnit\Framework\TestCase;

final class AnalyzingDatabaseBuilderTest extends TestCase
{
    private static function point(): DatabasePoint
    {
        return new DatabasePoint('shared', 1000, ['n' => 1000]);
    }

    /** @return object{log: list<string>} a builder that records what was asked of it, in the same log as the statements */
    private static function inner(array &$log): DatabaseBuilder
    {
        return new class($log) implements DatabaseBuilder {
            /** @param list<string> $log */
            public function __construct(private array &$log)
            {
            }

            public function build(DatabasePoint $point): array
            {
                $this->log[] = 'build ' . $point->key();

                return ['rows' => 1000];
            }

            public function drop(DatabasePoint $point): void
            {
                $this->log[] = 'drop ' . $point->key();
            }
        };
    }

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
                $this->log[] = $sql;
                if (str_contains($sql, 'table_rows AS n')) {
                    $this->reads++;

                    return $this->reads === 1 ? [['t' => 'entities', 'n' => 90], ['t' => 'activities', 'n' => 10]] : [['t' => 'entities', 'n' => 1000], ['t' => 'activities', 'n' => 10]];
                }
                if (str_contains($sql, 'information_schema.TABLES')) {
                    return [['t' => 'entities'], ['t' => 'activities']];
                }
                if (str_starts_with($sql, 'ANALYZE TABLE')) {
                    return [['Msg_type' => $this->analyzeError ? 'Error' : 'status', 'Msg_text' => $this->analyzeError ? 'broken' : 'OK']];
                }
                if (str_contains($sql, '@@innodb_stats_persistent')) {
                    return [['persistent' => 1, 'sample_pages' => 20, 'auto_recalc' => 1, 'version' => 'x']];
                }

                return [];
            }
        });
    }

    public function test_it_analyses_every_table_after_the_build_and_then_stops_the_automatic_recalculation(): void
    {
        $log = [];
        $builder = new AnalyzingDatabaseBuilder(self::inner($log), function (DatabasePoint $p) use (&$log): StatsStatements {
            $log[] = 'stats for ' . $p->key();

            return self::stats($log);
        });

        $built = $builder->build(self::point());

        $this->assertSame([
            'build shared:1000',
            'stats for shared:1000',
            "SELECT table_name AS t FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name",
            'SET SESSION information_schema_stats_expiry = 0',
            "SELECT table_name AS t, table_rows AS n FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name",
            'ANALYZE TABLE `entities`',
            'ANALYZE TABLE `activities`',
            'ALTER TABLE `entities` STATS_AUTO_RECALC=0',
            'ALTER TABLE `activities` STATS_AUTO_RECALC=0',
            'SET SESSION information_schema_stats_expiry = 0',
            "SELECT table_name AS t, table_rows AS n FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name",
            'SELECT @@innodb_stats_persistent AS persistent, @@innodb_stats_persistent_sample_pages AS sample_pages, @@innodb_stats_auto_recalc AS auto_recalc, @@version AS version',
        ], $log);
        $this->assertSame(1000, $built['rows'], 'what the inner builder said is kept');
        $this->assertSame(2, $built['statistics']['analyzed_tables']);
        $this->assertSame(2, $built['statistics']['auto_recalc_off_on']);
        $this->assertSame(['entities' => ['before' => 90, 'after' => 1000]], $built['statistics']['table_rows_changed'], 'only the table whose figure changed');
    }

    public function test_an_error_message_of_analyze_table_stops_the_build_before_the_recalculation_is_changed(): void
    {
        $log = [];
        $builder = new AnalyzingDatabaseBuilder(self::inner($log), function () use (&$log): StatsStatements {
            return self::stats($log, true);
        });

        try {
            $builder->build(self::point());
            $this->fail('the error was passed over');
        } catch (BuildFailure $e) {
            $this->assertStringContainsString('ANALYZE TABLE reported an error', $e->getMessage());
            $this->assertStringContainsString('entities: broken', $e->getMessage());
        }
        $this->assertSame([], array_values(array_filter($log, static fn (string $l): bool => str_starts_with($l, 'ALTER TABLE'))));
    }

    public function test_a_drop_goes_straight_to_the_inner_builder(): void
    {
        $log = [];
        $builder = new AnalyzingDatabaseBuilder(self::inner($log), static fn (): StatsStatements => throw new \LogicException('no statistics on a drop'));
        $builder->drop(self::point());

        $this->assertSame(['drop shared:1000'], $log);
    }
}
