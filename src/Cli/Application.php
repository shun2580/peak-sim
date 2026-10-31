<?php

declare(strict_types=1);

namespace PeakSim\Cli;

use PeakSim\Application\AnalysisConfig;
use PeakSim\Application\AnalysisRunner;
use PeakSim\Application\UnknownRouteException;
use PeakSim\Input\ArtisanFailedException;
use PeakSim\Model\AnalysisReport;
use PeakSim\Name;
use PeakSim\Output\JsonRenderer;
use PeakSim\Output\TableRenderer;

/**
 * The command line. Exit codes: 0 done, 2 usage, 3 input (artisan or its JSON), 4 internal error. The report goes
 * to standard output and nothing else does; messages and --stats go to standard error, so that the output can be
 * piped or compared byte by byte.
 */
final class Application
{
    public const EXIT_OK = 0;
    public const EXIT_USAGE = 2;
    public const EXIT_INPUT = 3;
    public const EXIT_INTERNAL = 4;

    /** @var \Closure(AnalysisConfig): AnalysisRunner */
    private readonly \Closure $runnerFactory;

    /** @param (\Closure(AnalysisConfig): AnalysisRunner)|null $runnerFactory replaces AnalysisRunner::create (tests) */
    public function __construct(?\Closure $runnerFactory = null)
    {
        $this->runnerFactory = $runnerFactory ?? static fn (AnalysisConfig $c): AnalysisRunner => AnalysisRunner::create($c);
    }

    /**
     * @param list<string> $argv the program name, then the arguments
     * @param resource $stdout
     * @param resource $stderr
     */
    public function run(array $argv, $stdout, $stderr): int
    {
        $args = array_slice($argv, 1);
        if (Arguments::isHelp($args)) {
            fwrite($stdout, self::usage());

            return self::EXIT_OK;
        }

        try {
            $command = Arguments::parse($args);
            if (!is_dir($command->appRoot)) {
                throw new UsageError("<app-root> is not a directory: {$command->appRoot}");
            }

            return $this->analyze($command, $stdout, $stderr);
        } catch (UsageError | UnknownRouteException $e) {
            fwrite($stderr, Name::TOOL_NAME . ': ' . $e->getMessage() . "\n\n" . self::usage());

            return self::EXIT_USAGE;
        } catch (ArtisanFailedException $e) {
            fwrite($stderr, Name::TOOL_NAME . ': could not read the routes and models of the app.' . "\n" . $e->getMessage() . "\n");

            return self::EXIT_INPUT;
        } catch (\Throwable $e) {
            fwrite($stderr, Name::TOOL_NAME . ': internal error: ' . $e::class . ': ' . $e->getMessage() . "\n");

            return self::EXIT_INTERNAL;
        }
    }

    /**
     * @param resource $stdout
     * @param resource $stderr
     */
    private function analyze(Command $command, $stdout, $stderr): int
    {
        $started = hrtime(true);
        $runner = ($this->runnerFactory)(new AnalysisConfig(
            appRoot: $command->appRoot,
            artisanCommand: $command->artisanCommand,
            exportDir: $command->exportDir,
            maxDepth: $command->maxDepth,
        ));

        $report = $command->route === null
            ? $runner->analyzeAll()
            : new AnalysisReport([$runner->analyzeRoute($command->route)], [], $runner->scope());

        // Rendered before anything is written: a failure while rendering must not leave half a report on stdout.
        $text = $command->format === Command::FORMAT_JSON
            ? (new JsonRenderer())->render($report, $runner->inputMode())
            : (new TableRenderer())->render($report);
        fwrite($stdout, str_ends_with($text, "\n") ? $text : $text . "\n");

        $stats = $runner->stats();
        // What artisan said when it could not describe a model (no database, say): the reason a route turned unknown.
        foreach ($stats['artisan_diagnostics'] as $note) {
            fwrite($stderr, Name::TOOL_NAME . ': note: ' . $note . "\n");
        }
        if ($command->stats) {
            fwrite($stderr, self::stats($stats, (hrtime(true) - $started) / 1e9));
        }

        return self::EXIT_OK;
    }

    /** @param array<string, mixed> $stats */
    private static function stats(array $stats, float $seconds): string
    {
        $lines = [
            Name::TOOL_NAME . ' stats',
            sprintf('  elapsed: %.3f s', $seconds),
            "  routes analysed: {$stats['routes']}",
            "  files under app/: {$stats['files']}",
            "  summaries: {$stats['summaries_computed']} computed, {$stats['summaries_reused']} reused",
            "  deepest call depth: {$stats['deepest_level']}",
        ];

        return implode("\n", $lines) . "\n";
    }

    private static function usage(): string
    {
        $name = Name::TOOL_NAME;

        return <<<TEXT
        usage: {$name} analyze <app-root> [options]

        Estimates, for each route of a Laravel app, how the number of queries and the memory grow with the data.

        options:
          --artisan-command=<cmd>   how to run artisan (default: php artisan, in <app-root>), e.g. "docker compose exec app php artisan"
          --export-dir=<dir>        read route-list.json and model-show/*.json exported beforehand, instead of running artisan
                                    (--artisan-command and --export-dir are mutually exclusive)
          --format=table|json       output format (default: table)
          --route=<uri>             analyse this one route only
          --max-depth=<n>           deepest call chain to follow (default: 20)
          --stats                   print timing and counts to standard error

        exit codes: 0 done, 2 usage, 3 input (artisan or its JSON), 4 internal error

        TEXT;
    }
}
