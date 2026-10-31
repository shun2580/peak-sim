<?php

declare(strict_types=1);

namespace PeakSim\Application;

use PeakSim\Analysis\Engine\Engine;
use PeakSim\Analysis\Engine\EngineOptions;
use PeakSim\Binding\ProviderBindingTable;
use PeakSim\Input\ArtisanSource;
use PeakSim\Input\CommandArtisanSource;
use PeakSim\Input\ExportDirSource;
use PeakSim\Input\ProcessRunner;
use PeakSim\Input\RouteEntry;
use PeakSim\Judge\Judge;
use PeakSim\Model\AnalysisReport;
use PeakSim\Model\Axis;
use PeakSim\Model\Gap;
use PeakSim\Model\GapCode;
use PeakSim\Model\RouteResult;
use PeakSim\Model\Site;
use PeakSim\Model\Summary;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Source\AppModelCatalog;
use PeakSim\Source\AppSourceIndex;
use PeakSim\Source\ParseFailed;

/**
 * The tool as one object: reads the routes and models (ArtisanSource), walks each controller action with the
 * Engine, and lets the Judge turn what was found into a verdict. This is the only place that knows how the parts
 * fit together.
 *
 * Closure routes and routes the framework registers (`storage/{path}`) have no action of the app to walk. They are
 * reported as skipped, with their kind, and are not analysed: "unknown" would claim the tool tried and failed.
 */
final class AnalysisRunner
{
    /** What the tool does not look at. Declared, not safe: a route that depends on these is still only as good as this list. */
    private const NOT_COVERED = [
        'authorization (gates and policies)',
        'events and listeners other than model observers',
        'form request validation',
        'global scopes',
        'middleware',
        'route model binding',
    ];

    private const FRAMEWORK_NAMESPACES = ['Illuminate', 'Laravel', 'Symfony', 'Livewire'];

    private int $routesAnalysed = 0;

    private function __construct(
        private readonly AnalysisConfig $config,
        private readonly ArtisanSource $artisan,
        private readonly AppSourceIndex $source,
        private readonly Engine $engine,
        private readonly Judge $judge,
    ) {
    }

    public static function create(AnalysisConfig $config, ?ProcessRunner $runner = null): self
    {
        $artisan = self::artisanSource($config, $runner);
        $source = new AppSourceIndex($config->appRoot);
        $wired = HandlerWiring::wire($config->appRoot, $config->viewPath);
        $engine = new Engine(
            $source,
            new AppModelCatalog($source, $artisan),
            new ProviderBindingTable($source),
            $wired->registry,
            new EngineOptions(maxDepth: $config->maxDepth),
            $wired->hook,
        );

        return new self($config, $artisan, $source, $engine, new Judge());
    }

    /** 'export-dir' or 'artisan-command': how the routes and models were read (the JSON names it). */
    public function inputMode(): string
    {
        return $this->config->exportDir !== null ? 'export-dir' : 'artisan-command';
    }

    public function analyzeAll(): AnalysisReport
    {
        $routes = [];
        $skipped = [];
        foreach ($this->artisan->routes() as $entry) {
            $kind = self::skippedKind($entry);
            if ($kind !== null) {
                $skipped[] = self::skippedEntry($entry, $kind);
                continue;
            }
            $routes[] = $this->analyzeEntry($entry);
        }
        usort($routes, static fn (RouteResult $a, RouteResult $b): int => [$a->uri, implode('|', $a->methods)] <=> [$b->uri, implode('|', $b->methods)]);

        return new AnalysisReport($routes, $skipped, $this->scope());
    }

    /**
     * @throws UnknownRouteException when no route has that URI, or the route is skipped (closure / framework)
     */
    public function analyzeRoute(string $uri): RouteResult
    {
        $wanted = '/' . ltrim($uri, '/');
        $found = null;
        foreach ($this->artisan->routes() as $entry) {
            if ($entry->uri !== $wanted) {
                continue;
            }
            $kind = self::skippedKind($entry);
            if ($kind !== null) {
                throw new UnknownRouteException("route {$wanted} is a {$kind} route and is not analysed");
            }
            // The same URI may exist under several methods: the first, in the route list's order, is the one asked for.
            $found ??= $entry;
        }
        if ($found === null) {
            throw new UnknownRouteException("no route {$wanted}");
        }

        return $this->analyzeEntry($found);
    }

    /** @return array<string, mixed> what the tool declares it does not look at (the report's analysis_scope) */
    public function scope(): array
    {
        return ['not_covered' => self::NOT_COVERED];
    }

    /**
     * Numbers for --stats. Valid after an analysis.
     *
     * @return array{routes: int, files: int, summaries_computed: int, summaries_reused: int, deepest_level: int, artisan_diagnostics: list<string>}
     */
    public function stats(): array
    {
        return [
            'routes' => $this->routesAnalysed,
            'files' => count($this->source->files('app')),
            'artisan_diagnostics' => $this->artisan instanceof CommandArtisanSource ? $this->artisan->diagnostics() : [],
        ] + $this->engine->stats();
    }

    private function analyzeEntry(RouteEntry $entry): RouteResult
    {
        $this->routesAnalysed++;
        [$class, $method] = self::splitAction($entry->action);
        try {
            $summary = $this->engine->analyzeAction($class, $method);
        } catch (ParseFailed $e) {
            // A file the parser cannot read: the route is unknown, with the reason. Never a verdict.
            $summary = new Summary([], [new Gap(GapCode::PARSE_FAILED, new Site($entry->path ?? '', 1), $e->getMessage(), [Axis::Query, Axis::Memory], 0, [])], [], [], new PlainT());
        }

        return $this->judge->judge($entry, $summary);
    }

    /** @return array{0: string, 1: string} */
    private static function splitAction(string $action): array
    {
        $action = ltrim($action, '\\');
        if (str_contains($action, '@')) {
            [$class, $method] = explode('@', $action, 2);

            return [$class, $method];
        }

        return [$action, '__invoke'];
    }

    /** closure | framework, or null for an action of the app. */
    private static function skippedKind(RouteEntry $entry): ?string
    {
        if ($entry->action === 'Closure') {
            return str_starts_with($entry->path ?? '', 'vendor/') ? RouteResult::KIND_FRAMEWORK : RouteResult::KIND_CLOSURE;
        }
        $namespace = explode('\\', ltrim($entry->action, '\\'))[0];

        return in_array($namespace, self::FRAMEWORK_NAMESPACES, true) ? RouteResult::KIND_FRAMEWORK : null;
    }

    /** @return array{route: string, methods: list<string>, action: string, kind: string} */
    private static function skippedEntry(RouteEntry $entry, string $kind): array
    {
        $methods = $entry->methods;
        sort($methods, SORT_STRING);

        return ['route' => $entry->uri, 'methods' => array_values($methods), 'action' => $entry->action, 'kind' => $kind];
    }

    private static function artisanSource(AnalysisConfig $config, ?ProcessRunner $runner): ArtisanSource
    {
        if ($config->exportDir !== null && $config->artisanCommand !== null) {
            throw new \InvalidArgumentException('exportDir and artisanCommand are exclusive');
        }
        if ($config->exportDir !== null) {
            return new ExportDirSource($config->exportDir);
        }

        return new CommandArtisanSource($config->appRoot, $config->artisanCommand ?? 'php artisan', $runner);
    }
}
