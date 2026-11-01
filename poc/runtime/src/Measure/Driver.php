<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

use PeakSim\Runtime\Judge\Verdict;
use PeakSim\Runtime\Report\RunReport;
use PeakSim\Runtime\Route\UserProvider;
use PeakSim\Runtime\Run\Attempt;

/**
 * The whole measurement, with the parts injected (the planner says which databases, the builder makes them, the
 * runner measures one route on one):
 *
 *   for each database a route needs (empty system first), in a fixed order:
 *     build it, measure every route that needs it, drop it
 *
 * One database holds one scale of one data plan, so routes that share a plan share the database and the migration is
 * paid once per scale, not once per route. Every request inside is rolled back (bin/measure-one). A request that
 * could not be rolled back makes the runner stop with 14: the database is dropped and built again, and the run goes on.
 *
 * Stops the whole run: a database that cannot be built (13), an app that does not start (12), a refusal (10), a
 * database that cannot be rebuilt clean (14). Whatever was measured by then is kept in the result.
 */
final class Driver
{
    /** @var list<array<string, mixed>> what the builder said about each database it built, rebuilds included */
    private array $builds = [];

    public function __construct(
        private readonly DataPlanner $planner,
        private readonly DatabaseBuilder $builder,
        private readonly OneRunner $runner,
    ) {
    }

    /**
     * @param list<RouteTask> $tasks
     * @param list<int>       $scales
     */
    public function run(array $tasks, array $scales): DriverResult
    {
        $points = $this->plan($tasks, $scales);

        /** @var array<int, list<Attempt>> $byTask */
        $byTask = array_fill_keys(array_keys($tasks), []);
        $all = [];
        $timings = [];
        $this->builds = [];
        $exit = MeasureExit::OK;
        $message = null;

        foreach ($points as $entry) {
            /** @var DatabasePoint $point */
            $point = $entry['point'];
            $built = false;
            $started = hrtime(true);
            $measureNanos = 0;
            $routes = 0;
            try {
                try {
                    $this->builds[] = ['point' => $point->key()] + $this->builder->build($point);
                    $built = true;
                } catch (BuildFailure $e) {
                    $exit = MeasureExit::DATA;
                    $message = sprintf('could not build the database %s: %s', $point->key(), $e->getMessage());
                    break;
                }
                $buildNanos = hrtime(true) - $started;

                foreach ($entry['items'] as [$index, $axis]) {
                    $task = $tasks[$index];
                    $began = hrtime(true);
                    $stop = $this->measure($task, $point, $axis, $byTask[$index], $all);
                    $measureNanos += hrtime(true) - $began;
                    ++$routes;
                    if ($stop !== null) {
                        [$exit, $message] = $stop;
                        break 2;
                    }
                }
            } finally {
                $timings[] = [
                    'point' => $point->key(),
                    'build_seconds' => round(($buildNanos ?? (hrtime(true) - $started)) / 1e9, 2),
                    'measure_seconds' => round($measureNanos / 1e9, 2),
                    'routes' => $routes,
                ];
                $this->dropQuietly($point, $built);
            }
        }

        return new DriverResult($exit, $message, $this->report($tasks, $byTask, $exit === MeasureExit::OK ? null : (string) $message), $all, $timings, $this->builds);
    }

    /**
     * @param list<RouteTask> $tasks
     * @param list<int>       $scales
     *
     * @return list<array{point: DatabasePoint, items: list<array{int, string}>}>
     */
    private function plan(array $tasks, array $scales): array
    {
        $points = [];
        foreach ($tasks as $index => $task) {
            if ($task->failClass !== null) {
                continue;
            }
            foreach ($this->planner->sweepsFor($task->spec, $scales) as $sweep) {
                foreach ($sweep->points as $point) {
                    $key = $point->key();
                    if (!isset($points[$key])) {
                        $points[$key] = ['point' => $point, 'items' => []];
                    } elseif ($points[$key]['point']->recipe !== $point->recipe) {
                        throw new \LogicException(sprintf('the data plan %s is asked for with two different recipes', $key));
                    }
                    $points[$key]['items'][] = [$index, $sweep->axis];
                }
            }
        }
        uasort($points, static fn (array $a, array $b): int => [$a['point']->tag, $a['point']->scale] <=> [$b['point']->tag, $b['point']->scale]);

        return array_values($points);
    }

    /**
     * Measures one route on the database that is up. Returns null to go on, or [exit code, message] to stop the run.
     *
     * @param list<Attempt> $mine this route's attempts
     * @param list<Attempt> $all  every attempt
     *
     * @return array{int, string}|null
     */
    private function measure(RouteTask $task, DatabasePoint $point, string $axis, array &$mine, array &$all): ?array
    {
        $request = new MeasureRequest(
            $point->tag,
            $point->scale,
            $axis,
            $task->spec->uri,
            (string) $task->uri,
            $task->spec->method,
            $task->actAs,
            $task->body,
            $task->form,
            $task->csrf,
            $task->headers,
            false,
            $point->scale === 0 ? [Attempt::WARMUP, Attempt::COLD] : [Attempt::WARMUP, Attempt::COLD, Attempt::WARM],
        );

        $result = $this->runner->run($request);
        $stop = $this->take($result, $request, $point, $mine, $all);
        if ($stop !== null) {
            return $stop;
        }

        // A 403 gets exactly one more try with every Gate check let through; the value is marked and only a reference.
        $first = $result->attempts[0] ?? null;
        if ($result->exit === 0 && $first !== null && $first->class === 'forbidden' && UserProvider::shouldRetryWithGateBypass(403, false)) {
            $retry = $request->withGateBypass();
            $again = $this->runner->run($retry);

            return $this->take($again, $retry, $point, $mine, $all);
        }

        return null;
    }

    /**
     * @param list<Attempt> $mine
     * @param list<Attempt> $all
     *
     * @return array{int, string}|null
     */
    private function take(OneResult $result, MeasureRequest $request, DatabasePoint $point, array &$mine, array &$all): ?array
    {
        $label = sprintf('%s %s on %s', $request->method, $request->route, $point->key());

        switch ($result->exit) {
            case MeasureExit::REFUSED:
                $this->keep($result->attempts, $mine, $all);

                return [MeasureExit::REFUSED, $label . ': ' . self::tail($result->stderr, 'refused')];
            case MeasureExit::APP_START:
                return [MeasureExit::APP_START, $label . ': ' . self::tail($result->stderr, 'the app did not start')];
            case MeasureExit::CONTAMINATED:
                $this->keep($result->attempts, $mine, $all);
                try {
                    $this->builder->drop($point);
                    $this->builds[] = ['point' => $point->key(), 'rebuilt_after' => $request->route] + $this->builder->build($point);
                } catch (\Throwable $e) {
                    return [MeasureExit::CONTAMINATED, $label . ': the database could not be rebuilt after the request changed it: ' . $e->getMessage()];
                }

                return null;
            case MeasureExit::OK:
                $this->keep($result->attempts, $mine, $all);

                return null;
            default:
                if ($result->attempts !== []) {
                    $this->keep($result->attempts, $mine, $all);
                } else {
                    $this->keep([new Attempt($request->route, $request->method, $request->scale, $request->axis, Attempt::COLD, null, null, [], [], null, null, $request->gateBypass, $result->exit === Proc::TIMED_OUT ? 'timeout' : 'runner_failed', 'exit ' . $result->exit . ': ' . self::tail($result->stderr, 'no output'))], $mine, $all);
                }

                return null;
        }
    }

    /**
     * @param list<Attempt> $attempts
     * @param list<Attempt> $mine
     * @param list<Attempt> $all
     */
    private function keep(array $attempts, array &$mine, array &$all): void
    {
        foreach ($attempts as $attempt) {
            $mine[] = $attempt;
            $all[] = $attempt;
        }
    }

    /**
     * @param list<RouteTask>             $tasks
     * @param array<int, list<Attempt>>   $byTask
     */
    private function report(array $tasks, array $byTask, ?string $stopped): RunReport
    {
        $report = new RunReport();
        foreach ($tasks as $index => $task) {
            $attempts = $byTask[$index];
            $failClass = $task->failClass;
            $failDetail = $task->failDetail;
            if ($failClass === null && $attempts === []) {
                $failClass = 'not_run';
                $failDetail = $stopped === null ? 'no database was planned for this route' : 'the run stopped before this route: ' . $stopped;
            } elseif ($failClass === null && !self::hasMeasure($attempts)) {
                $last = $attempts[array_key_last($attempts)];
                $failClass = $last->class;
                $failDetail = $last->detail;
            }
            $row = RunReport::rowFor($task->spec, $attempts, $failClass, $failDetail);
            if ($row['verdict'] === Verdict::NOT_MEASURED && $row['class'] === 'ok') {
                // Too few points were left to judge: say which scale failed and why, not that all went well.
                foreach ($attempts as $a) {
                    if ($a->class !== 'ok') {
                        $row['class'] = $a->class;
                        $row['reason_detail'] = sprintf('scale %d (axis %s): %s', $a->scale, $a->axis, $a->detail ?? $a->class);

                        break;
                    }
                }
            }
            $report->add($row);
        }

        return $report;
    }

    /** @param list<Attempt> $attempts */
    private static function hasMeasure(array $attempts): bool
    {
        foreach ($attempts as $a) {
            if ($a->attempt === Attempt::COLD && $a->class === 'ok' && $a->queries !== null) {
                return true;
            }
        }

        return false;
    }

    private function dropQuietly(DatabasePoint $point, bool $built): void
    {
        try {
            $this->builder->drop($point);
        } catch (\Throwable) {
            // The run record already says what happened; bin/cleanup removes whatever is left.
        }
    }

    private static function tail(string $text, string $fallback): string
    {
        $text = trim($text);

        return $text === '' ? $fallback : substr(str_replace("\n", ' ', $text), -300);
    }
}
