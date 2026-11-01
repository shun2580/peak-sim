<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

use PeakSim\Runtime\Judge\Verdict;
use PeakSim\Runtime\Report\Comparison;

/**
 * Writes the record of a run into one directory (outside the repository):
 *
 *   attempts.jsonl    every attempt of every route, one JSON object per line
 *   routes.jsonl      one row per route: outcome, verdict with its scope, standing against the static analysis
 *   report.md         the same, for reading; conflicts first
 *   summary.json      counts, per-database timings, what each build produced
 *   comparison.md/json  with an expectations file: expectation, static analysis and measurement side by side
 *   tasks.json        what the inspection decided for each route (URI, login, body, or why not)
 */
final class OutputWriter
{
    public function __construct(private readonly string $dir)
    {
    }

    /**
     * @param array<string, mixed> $meta
     * @param list<RouteTask>      $tasks
     * @param list<array<string, mixed>>|null $expected the rows of an expectations file
     *
     * @return list<string> the files written
     */
    public function write(DriverResult $result, array $meta, array $tasks, ?array $expected): array
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('cannot create ' . $this->dir);
        }
        $files = [];
        $profile = isset($meta['profile']) && is_string($meta['profile']) ? $meta['profile'] : null;
        $report = $result->report->withProfile($profile);

        $lines = '';
        foreach ($result->attempts as $attempt) {
            $lines .= $attempt->toJson() . "\n";
        }
        $files[] = $this->put('attempts.jsonl', $lines);
        $files[] = $this->put('routes.jsonl', $report->toJsonLines());
        $files[] = $this->put('report.md', $report->toMarkdown());
        $files[] = $this->put('tasks.json', self::json(array_map(static fn (RouteTask $t): array => $t->toArray(), $tasks)));
        $files[] = $this->put('summary.json', self::json([
            'meta' => $meta,
            'exit' => $result->exit,
            'message' => $result->message,
            'summary' => $report->summary(),
            'timings' => $result->timings,
            'builds' => array_map(self::buildSummary(...), $result->builds),
        ]));

        if ($expected !== null) {
            $staticByRoute = [];
            $verdicts = [];
            foreach ($report->rows() as $row) {
                $staticByRoute[$row['route']] = $row['static'];
                $verdicts[$row['route']] = Verdict::fromArray($row);
            }
            $table = Comparison::table($expected, $staticByRoute, $verdicts);
            $files[] = $this->put('comparison.json', self::json($table));
            $files[] = $this->put('comparison.md', Comparison::toMarkdown($table, $profile));
        }

        return $files;
    }

    /**
     * @param array<string, mixed> $build
     *
     * @return array<string, mixed>
     */
    private static function buildSummary(array $build): array
    {
        return array_intersect_key($build, array_flip(['point', 'rebuilt_after', 'database', 'mode', 'scale', 'laravel', 'migrate_seconds', 'fill_seconds', 'total_seconds', 'rows', 'generation']));
    }

    private function put(string $name, string $content): string
    {
        $path = $this->dir . '/' . $name;
        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException('cannot write ' . $path);
        }

        return $path;
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    }
}
