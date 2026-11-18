<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

use PeakSim\Runtime\Explain\StatsStatements;

/**
 * The last part of building a measurement database for an app that keeps a table of its own making up to date by a
 * maintenance process: the profile's `rebuild` steps, then ANALYZE TABLE on every table. The order is fixed here and is
 * the same for every database made by `bin/inner/build-db.php` (the lab's databases, the capture's, the measurement's):
 *
 *   build (migrate, generate, the profile's fixups)  ->  rebuild (the app's own maintenance command)  ->  ANALYZE
 *
 * A step that fails stops the build (a BuildFailure, so exit 13 of build-db.php): a database whose rebuild did not finish
 * is never analysed and never handed on. The ANALYZE is not run when a step failed. A profile with no `rebuild` gets
 * nothing here (null): for such an app the build is as it always was.
 *
 * The app is reached through three small doors so the order and the stop can be tried without a database: the artisan
 * call, the row count of a table, and the statistics statements.
 */
final class RebuildStep
{
    /**
     * @param array<string, mixed>                       $profile
     * @param \Closure(string): array{0: int, 1: string} $artisan   runs one artisan command; returns its exit code and its output
     * @param \Closure(string): int                      $countRows the number of rows of one plain table name
     * @param (\Closure(): float)|null                   $clock     seconds, for the timings (a test gives its own)
     *
     * @return array{order: list<string>, steps: list<array<string, mixed>>, analyze: array<string, mixed>}|null
     *         null when the profile has no `rebuild`
     *
     * @throws BuildFailure
     */
    public static function run(array $profile, \Closure $artisan, \Closure $countRows, StatsStatements $stats, ?\Closure $clock = null): ?array
    {
        $steps = $profile['rebuild'] ?? null;
        if (!is_array($steps) || $steps === []) {
            return null;
        }
        $clock ??= static fn (): float => microtime(true);

        $done = [];
        foreach ($steps as $step) {
            $name = (string) ($step['name'] ?? '');
            $command = (string) ($step['command'] ?? '');
            $tables = array_values(array_map('strval', (array) ($step['tables'] ?? [])));
            if ($name === '' || $command === '' || $tables === []) {
                throw new BuildFailure('a rebuild step of the profile has no name, command or tables');
            }
            $before = self::counts($countRows, $tables, $name);
            $started = $clock();
            try {
                [$code, $output] = $artisan($command);
            } catch (\Throwable $e) {
                throw new BuildFailure(sprintf('the rebuild "%s" (%s) failed: %s', $name, $command, self::tail($e->getMessage())), 0, $e);
            }
            if ($code !== 0) {
                throw new BuildFailure(sprintf('the rebuild "%s" (%s) failed with exit %d: %s', $name, $command, $code, self::tail($output)));
            }
            $seconds = round($clock() - $started, 2);
            $done[] = [
                'name' => $name,
                'command' => $command,
                'seconds' => $seconds,
                'rows_before' => $before,
                'rows_after' => self::counts($countRows, $tables, $name),
            ];
        }

        return ['order' => ['build', 'rebuild', 'analyze'], 'steps' => $done, 'analyze' => self::analyze($stats, $clock)];
    }

    /**
     * ANALYZE TABLE on every base table of the database the statistics door is on, without touching the automatic
     * recalculation (the lab leaves it on, D181; the capture switches it off afterwards with `AnalyzingDatabaseBuilder`).
     *
     * @return array{tables: int, seconds: float, table_rows_changed: array<string, array{before: int|null, after: int}>}
     *
     * @throws BuildFailure when ANALYZE TABLE reports an error on a table
     */
    private static function analyze(StatsStatements $stats, \Closure $clock): array
    {
        $started = $clock();
        try {
            $tables = $stats->tables();
            $before = $stats->tableRows();
            $analyzed = $stats->analyze($tables);
            $errors = [];
            foreach ($analyzed as $table => $messages) {
                foreach ($messages as $m) {
                    if (strtolower((string) ($m['Msg_type'] ?? '')) === 'error') {
                        $errors[] = $table . ': ' . (string) ($m['Msg_text'] ?? '');
                    }
                }
            }
            if ($errors !== []) {
                throw new BuildFailure('ANALYZE TABLE reported an error: ' . implode('; ', array_slice($errors, 0, 3)));
            }
            $after = $stats->tableRows();
        } catch (BuildFailure $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new BuildFailure('ANALYZE TABLE after the rebuild failed: ' . self::tail($e->getMessage()), 0, $e);
        }
        $changed = [];
        foreach ($after as $table => $rows) {
            if (($before[$table] ?? null) !== $rows) {
                $changed[$table] = ['before' => $before[$table] ?? null, 'after' => $rows];
            }
        }

        return ['tables' => count($tables), 'seconds' => round($clock() - $started, 2), 'table_rows_changed' => $changed];
    }

    /**
     * @param \Closure(string): int $countRows
     * @param list<string>          $tables
     *
     * @return array<string, int>
     *
     * @throws BuildFailure
     */
    private static function counts(\Closure $countRows, array $tables, string $step): array
    {
        $counts = [];
        foreach ($tables as $table) {
            try {
                $counts[$table] = $countRows($table);
            } catch (\Throwable $e) {
                throw new BuildFailure(sprintf('the rows of "%s" could not be counted around the rebuild "%s": %s', $table, $step, self::tail($e->getMessage())), 0, $e);
            }
        }

        return $counts;
    }

    private static function tail(string $text): string
    {
        return substr(trim(preg_replace('/\s+/', ' ', $text) ?? $text), -400);
    }
}
