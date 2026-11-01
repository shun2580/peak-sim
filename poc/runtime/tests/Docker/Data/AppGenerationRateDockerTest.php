<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Docker\Data;

use PeakSim\Runtime\Data\Generator;
use PeakSim\Runtime\Data\Reason;
use PeakSim\Runtime\Data\ScalePlan;
use PeakSim\Runtime\Data\TableOutcome;
use PeakSim\Runtime\Tests\Docker\Data\Support\GenerationCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The generation success rate (per table) for whatever app is mounted at /app: migrate its schema into a
 * measurement database, generate with a global plan, and write the report. The test checks that the report is
 * honest (every table accounted for, every failure has a reason from the fixed vocabulary, every generated table
 * has the planned number of rows); it does NOT set a threshold: the rate is the result.
 *
 * Run it against another app by starting the stack with PEAK_APP_ROOT pointing at that app, then
 *   bin/stack exec /peak-sim/vendor/bin/phpunit -c /peak-sim/poc/runtime/phpunit.runtime.xml --filter AppGenerationRate
 */
#[Group('in-stack')]
final class AppGenerationRateDockerTest extends GenerationCase
{
    private const SCALES = [100, 1000];

    private const REASONS = [
        Reason::FK_CYCLE, Reason::UNSUPPORTED_TYPE, Reason::CHECK_CONSTRAINT, Reason::UNIQUE_VIOLATION,
        Reason::FK_VIOLATION, Reason::NOT_NULL_VIOLATION, Reason::NO_DEFAULT, Reason::DATA_TOO_LONG,
        Reason::OUT_OF_RANGE, Reason::INSERT_ERROR, Reason::PARENT_NOT_GENERATED, Reason::COUNT_MISMATCH,
        Reason::AUTOINC_GAP, Reason::FRAMEWORK_TABLE, Reason::PROFILE_SKIP,
    ];

    public static function tearDownAfterClass(): void
    {
        foreach (self::SCALES as $scale) {
            self::drop($scale);
        }
    }

    /** @return array<string, array{0: int}> */
    public static function scales(): array
    {
        return ['n=100' => [100], 'n=1000' => [1000]];
    }

    #[DataProvider('scales')]
    public function testTheReportAccountsForEveryTableHonestly(int $scale): void
    {
        $migration = self::fresh($scale);
        $pdo = self::pdo($scale);

        $started = microtime(true);
        $report = (new Generator())->generate($pdo, ScalePlan::global($scale, 5), []);
        $seconds = round(microtime(true) - $started, 2);

        self::assertCount($migration['tables'], $report->outcomes, 'one outcome per base table');
        foreach ($report->outcomes as $outcome) {
            if ($outcome->status === TableOutcome::GENERATED) {
                self::assertSame($outcome->existing + $outcome->planned, self::rowCount($pdo, $outcome->table), $outcome->table);
                self::assertNull($outcome->reason);
            } else {
                self::assertContains($outcome->reason, self::REASONS, $outcome->table);
                self::assertNotSame('', (string) $outcome->detail, $outcome->table);
                self::assertLessThanOrEqual(TableOutcome::DETAIL_LIMIT, strlen((string) $outcome->detail));
            }
            if ($outcome->status === TableOutcome::FAILED) {
                // a failed table left nothing behind: only the rows a migration put there
                self::assertSame($outcome->existing, self::rowCount($pdo, $outcome->table), $outcome->table);
            }
        }

        $composer = is_file(self::config()->appRoot . '/composer.json')
            ? json_decode((string) file_get_contents(self::config()->appRoot . '/composer.json'), true)
            : [];
        $name = preg_replace('/[^a-z0-9]+/', '-', strtolower((string) ($composer['name'] ?? 'app'))) ?? 'app';
        self::writeOut('generation-' . trim($name, '-') . '-n' . $scale . '.json', [
            'app' => $composer['name'] ?? null,
            'laravel' => $migration['laravel'] ?? null,
            'plan' => 'global n=' . $scale . ' base=5',
            'tables_in_schema' => $migration['tables'],
            'migrate_seconds' => $migration['seconds'],
            'generate_seconds' => $seconds,
        ] + $report->toArray());
    }
}
