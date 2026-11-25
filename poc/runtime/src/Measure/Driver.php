<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

use PeakSim\Runtime\Judge\Verdict;
use PeakSim\Runtime\Report\RunReport;
use PeakSim\Runtime\Route\BodyBuilder;
use PeakSim\Runtime\Route\UserProvider;
use PeakSim\Runtime\Run\Attempt;

/**
 * The whole measurement, with the parts injected (the planner says which databases, the builder makes them, the
 * runner measures one route on one):
 *
 *   for each database a route needs (empty system first), in a fixed order:
 *     build it, measure every route that needs it, and keep it (it is dropped only when the run is given `dropDatabases`)
 *
 * One database holds one scale of one data plan, so routes that share a plan share the database and the migration is
 * paid once per scale, not once per route. Every request inside is rolled back (bin/measure-one). A request that
 * could not be rolled back makes the runner stop with 14: the database is dropped and built again, and the run goes on.
 *
 * The promise "a measured database is kept" is about the database the run has measured on. The rebuild above is not a
 * break of it: a database a request changed is no longer the one that was built, so it is made again before the next route.
 *
 * Stops the whole run: a database that cannot be built (13), an app that does not start (12), a refusal (10), a
 * database that cannot be rebuilt clean (14). Whatever was measured by then is kept in the result.
 */
final class Driver
{
    /** @var list<array<string, mixed>> what the builder said about each database it built, rebuilds included */
    private array $builds = [];

    /** The rounds of rebuilding a body after a 422: a string, a number, an array (see `BodyBuilder`). */
    private const VALIDATION_ROUNDS = 3;

    /** @var array<string, array<string, mixed>> the body that got past validation, by route: later scales start from it */
    private array $learnedBodies = [];

    public function __construct(
        private readonly DataPlanner $planner,
        private readonly DatabaseBuilder $builder,
        private readonly OneRunner $runner,
        private readonly bool $gateBypass = true,
        private readonly bool $warm = true,
        private readonly ?string $tapDir = null,
        /** @var list<string> the "METHOD uri" of the routes measured after every other route of their database (`RouteOrder`) */
        private readonly array $lastRoutes = [],
        /** false (default): every database is kept when its routes are measured; true: it is dropped then (`bin/measure --purge`) */
        private readonly bool $dropDatabases = false,
    ) {
    }

    /** Where the per-statement record of one (route, scale) goes: one file per route and scale, named from the route. */
    public static function tapFile(string $dir, string $method, string $route, int $scale): string
    {
        return rtrim($dir, '/') . '/' . substr(sha1($method . ' ' . $route), 0, 12) . '-s' . $scale . '.jsonl';
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
                } catch (BuildTimedOut $e) {
                    // The build was stopped and its statement killed: this scale is not run, and the run goes on to the next.
                    $this->builds[] = ['point' => $point->key(), 'not_run' => true, 'reason' => 'build_timeout', 'limit_seconds' => $e->limitSeconds] + $e->stopped() + ['still_running' => $e->stillRunning];
                    $this->recordNotRun($entry['items'], $tasks, $point, $e->getMessage(), $byTask, $all);
                    continue;
                } catch (BuildFailure $e) {
                    $exit = MeasureExit::DATA;
                    $message = sprintf('could not build the database %s: %s', $point->key(), $e->getMessage());
                    break;
                }
                $buildNanos = hrtime(true) - $started;

                foreach (RouteOrder::lastRoutesLast($entry['items'], static fn (array $item): string => $tasks[$item[0]]->spec->key(), $this->lastRoutes) as [$index, $axis]) {
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
                if ($this->dropDatabases) {
                    $this->dropQuietly($point, $built);
                }
            }
        }

        return new DriverResult($exit, $message, $this->report($tasks, $byTask, $exit === MeasureExit::OK ? null : (string) $message), $all, $timings, $this->builds, $this->learnedBodies);
    }

    /**
     * Every route that needed this database gets one attempt of class `not_run` at its scale, with the reason: the verdict of
     * the route is then judged on the scales that were measured, and says which scale was not.
     *
     * @param list<array{int, string}>    $items
     * @param list<RouteTask>             $tasks
     * @param array<int, list<Attempt>>   $byTask
     * @param list<Attempt>               $all
     */
    private function recordNotRun(array $items, array $tasks, DatabasePoint $point, string $reason, array &$byTask, array &$all): void
    {
        foreach ($items as [$index, $axis]) {
            $task = $tasks[$index];
            $attempt = new Attempt($task->spec->uri, $task->spec->method, $point->scale, $axis, Attempt::COLD, null, null, [], [], null, null, false, 'not_run', $reason);
            $byTask[$index][] = $attempt;
            $all[] = $attempt;
        }
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
            // The empty system, and a run that asked for it (`--warm=off`), leave out the warm request: the cold one decides.
            $point->scale === 0 || !$this->warm ? [Attempt::WARMUP, Attempt::COLD] : [Attempt::WARMUP, Attempt::COLD, Attempt::WARM],
            $task->query,
        );

        $learned = $this->learnedBodies[self::bodyKey($task)] ?? null;
        if ($learned !== null) {
            $request = $request->withBody($learned);
        }
        if ($this->tapDir !== null && $axis === 'N' && $point->scale > 0) {
            // A record left by an earlier try of this route and scale is removed first: only the last cold request counts.
            $file = self::tapFile($this->tapDir, $task->spec->method, $task->spec->uri, $point->scale);
            if (!is_dir($this->tapDir) && !@mkdir($this->tapDir, 0777, true) && !is_dir($this->tapDir)) {
                throw new \RuntimeException('cannot create ' . $this->tapDir);
            }
            @unlink($file);
            $request = $request->withTapOut($file);
        }
        $result = $this->runner->run($request);
        $stop = $this->take($result, $request, $point, $mine, $all);
        if ($stop !== null) {
            return $stop;
        }

        // A 422 names the fields it did not get: build the body again from them (a string, then a number, then an array
        // for a field that keeps failing) instead of recording the route as one that could not be reached.
        if ($result->exit === 0 && $request->method !== 'GET' && ($result->attempts[0]->class ?? null) === 'validation_422') {
            [$request, $result, $stop] = $this->rebuildAfter422($request, $result, $point, $mine, $all);
            if ($stop !== null) {
                return $stop;
            }
            if (($result->attempts[0]->class ?? null) !== 'validation_422' && $request->body !== null) {
                $this->learnedBodies[self::bodyKey($task)] = $request->body;
            }
        }

        // A 403 gets exactly one more try with every Gate check let through (unless the run switched that off); the
        // value is marked and only a reference.
        $first = $result->attempts[0] ?? null;
        if ($this->gateBypass && $result->exit === 0 && $first !== null && $first->class === 'forbidden' && UserProvider::shouldRetryWithGateBypass(403, false)) {
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
     * @return array{MeasureRequest, OneResult, array{int, string}|null} the last request made, its result, and a stop
     */
    private function rebuildAfter422(MeasureRequest $request, OneResult $result, DatabasePoint $point, array &$mine, array &$all): array
    {
        /** @var array<string, int> $types field => the type it was last tried with */
        $types = [];
        $base = $request->body ?? [];
        for ($round = 1; $round <= self::VALIDATION_ROUNDS; $round++) {
            $keys = $result->attempts[0]->errorKeys ?? [];
            if ($keys === []) {
                break;
            }
            foreach ($keys as $key) {
                $types[$key] = ($types[$key] ?? 0) + 1;
            }
            $body = $base;
            foreach ($types as $key => $type) {
                if ($type > self::VALIDATION_ROUNDS) {
                    break 2;
                }
                $body = array_replace_recursive($body, BodyBuilder::bodyFromErrorKeys([(string) $key], $type));
            }
            $request = $request->withBody($body);
            $result = $this->runner->run($request);
            $stop = $this->take($result, $request, $point, $mine, $all);
            if ($stop !== null) {
                return [$request, $result, $stop];
            }
            if (($result->attempts[0]->class ?? null) !== 'validation_422') {
                break;
            }
        }

        return [$request, $result, null];
    }

    private static function bodyKey(RouteTask $task): string
    {
        return $task->spec->method . ' ' . $task->spec->uri;
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
                if ($last->class === 'not_found' && $task->guessed !== []) {
                    // The app did not find a value the tool made up (a number, a sample of a pattern). That says nothing
                    // about the route: it is not a route that was hit. The attempts stay in the record of attempts.
                    $failClass = 'unfilled_param';
                    $failDetail = sprintf('the value of %s was a guess, not a row, and the app did not find it (not_found%s)', implode(', ', $task->guessed), $last->detail === null ? '' : ': ' . $last->detail);
                    $attempts = [];
                }
            }
            $row = RunReport::rowFor($task->spec, $attempts, $failClass, $failDetail);
            if ($task->credentials !== null) {
                // The route was reached through the app's own authentication with credentials the profile supplied,
                // not through a session login: a different path, so the record says it.
                $row['request_credentials'] = $task->credentials;
            }
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
            if ($row['verdict'] === Verdict::NOT_MEASURED && ($row['reason_detail'] ?? null) === null) {
                // A scale whose database could not be built in time is named with its reason, also when no scale was left.
                foreach ($attempts as $a) {
                    if ($a->class === 'not_run') {
                        $row['class'] = 'not_run';
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
            // The run record already says what happened; bin/cleanup (or --purge) removes whatever is left.
        }
    }

    private static function tail(string $text, string $fallback): string
    {
        $text = trim($text);

        return $text === '' ? $fallback : substr(str_replace("\n", ' ', $text), -300);
    }
}
