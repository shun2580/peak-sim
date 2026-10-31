<?php

declare(strict_types=1);

namespace PeakSim\Prototype;

/**
 * Prototype of the phase-1 judgement for a handful of routes: how the number of queries and the memory
 * grow with the data. It reads the pre-exported artisan JSON and the app source; it never runs artisan,
 * boots the app, or touches a database or the network.
 */
final class Analyzer
{
    private readonly ClassIndex $classes;

    private readonly ExportReader $export;

    private readonly MethodAnalyzer $methods;

    public function __construct(string $appRoot, string $exportDir)
    {
        $appRoot = rtrim((string) (realpath($appRoot) ?: $appRoot), '/');
        $this->classes = new ClassIndex($appRoot);
        $this->export = new ExportReader(rtrim($exportDir, '/'));
        $this->methods = new MethodAnalyzer($this->classes, $this->export, new BindingResolver($this->classes));
    }

    /** @param string $uri a route as in expected.json, e.g. '/ng/n-plus-one' (route:list has no leading slash) */
    public function analyzeRoute(string $uri): RouteAnalysis
    {
        $route = $this->export->findRoute(ltrim($uri, '/'));
        $action = is_array($route) ? (string) ($route['action'] ?? '') : '';
        if ($route === null || $action === '' || $action === 'Closure') {
            return new RouteAnalysis($uri, 'unknown', 'unknown', null, 'low', []);
        }

        [$class, $method] = str_contains($action, '@') ? explode('@', $action, 2) : [$action, '__invoke'];
        $summary = $this->methods->summarize($class, $method);

        return $this->judge($uri, $summary);
    }

    private function judge(string $uri, Summary $summary): RouteAnalysis
    {
        $queryDepth = 0;
        $memoryDepth = 0;
        $dominant = null;
        $dominantScore = -1;
        foreach ($summary->events as $event) {
            $memory = $event->unbounded ? $event->depth + 1 : ($event->kind === Event::LAZY ? $event->depth : 0);
            $queryDepth = max($queryDepth, $event->depth);
            $memoryDepth = max($memoryDepth, $memory);
            if (max($event->depth, $memory) > $dominantScore) {
                $dominant = $event;
                $dominantScore = max($event->depth, $memory);
            }
        }

        if ($summary->events === []) {
            // Nothing found: report "unknown" rather than O(1) when something could not be followed.
            $unknown = $summary->gaps !== [];

            return new RouteAnalysis(
                $uri,
                $unknown ? 'unknown' : 'O(1)',
                $unknown ? 'unknown' : 'O(1)',
                null,
                'low',
                $this->evidence($summary),
            );
        }

        $nTable = null;
        if ($dominant !== null && $dominantScore > 0) {
            $nTable = $dominant->depth > 0 && $dominant->loopTables !== [] ? $dominant->loopTables[0] : $dominant->table;
        }

        return new RouteAnalysis(
            $uri,
            self::label($queryDepth),
            self::label($memoryDepth),
            $nTable,
            $summary->gaps === [] ? 'high' : 'medium',
            $this->evidence($summary),
        );
    }

    private static function label(int $depth): string
    {
        return match (true) {
            $depth <= 0 => 'O(1)',
            $depth === 1 => 'O(N)',
            default => 'O(N*M)',
        };
    }

    /** @return list<array{file: string, line: int, note: string}> */
    private function evidence(Summary $summary): array
    {
        $items = [...$summary->trace];
        foreach ($summary->events as $event) {
            $items[] = ['file' => $event->file, 'line' => $event->line, 'note' => $event->note . ($event->depth > 0 ? " (inside {$event->depth} loop(s) over rows)" : '')];
        }
        foreach ($summary->gaps as $gap) {
            $items[] = ['file' => $gap['file'], 'line' => $gap['line'], 'note' => 'not followed: ' . $gap['note']];
        }

        $seen = [];
        $evidence = [];
        foreach ($items as $item) {
            $key = $item['file'] . ':' . $item['line'] . ':' . $item['note'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $evidence[] = ['file' => $this->classes->relative($item['file']), 'line' => $item['line'], 'note' => $item['note']];
        }

        return $evidence;
    }
}
