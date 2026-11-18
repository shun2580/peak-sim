<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Variance;

use PeakSim\Runtime\Measure\DataPlanner;
use PeakSim\Runtime\Measure\DatabasePoint;
use PeakSim\Runtime\Measure\MeasureRequest;
use PeakSim\Runtime\Measure\RouteOrder;
use PeakSim\Runtime\Measure\RouteTask;
use PeakSim\Runtime\Run\Attempt;

/**
 * Turns a recorded list of route tasks into what an outside runner needs to repeat one measurement on ONE database:
 * the arguments that build each database once, and, per route, the arguments of `bin/measure-one` exactly as the driver
 * would pass them (same `MeasureRequest`, same planner, so the same recipe and the same request).
 *
 * It decides nothing about how a route is judged and changes nothing in the driver. The only things it fixes are the ones
 * a repeat needs: the routes are taken in the order of the task list (the order the driver measures them in), and the
 * phases are warm-up and cold (the warm request is left out, as in a run with `--warm=off`).
 *
 * The build arguments mirror `ProcessDatabaseBuilder::build()`.
 */
final class TrialPlan
{
    /**
     * @param list<RouteTask> $tasks     the tasks of a recorded run, in their recorded order
     * @param list<string>    $routeKeys "METHOD uri" of the routes to repeat (`RouteSpec::key()`)
     * @param int             $scale     the size of the database (the main sweep's point)
     * @param string          $mode      generator or seeder
     * @param array<string, list<string>> $stateChanging the profile's routes that rewrite a table other routes read ("METHOD uri" => its tables, in the order they go): they go last in their database
     *
     * @return list<array{key: string, tag: string, scale: int, build_args: list<string>, routes: list<array{route: string, method: string, uri: string, measure_args: list<string>}>}>
     *         one entry per database to build, in the order its first route appears in the task list
     *
     * @throws \InvalidArgumentException a route is not in the list, is in it twice, or was not prepared (it has a fail class)
     */
    public static function build(array $tasks, array $routeKeys, int $scale, DataPlanner $planner, string $mode, ?string $profile = null, array $stateChanging = []): array
    {
        if ($routeKeys === [] || count(array_unique($routeKeys)) !== count($routeKeys)) {
            throw new \InvalidArgumentException('name at least one route, each once');
        }

        $found = array_fill_keys($routeKeys, 0);
        $groups = [];
        foreach ($tasks as $task) {
            $key = $task->spec->key();
            if (!isset($found[$key])) {
                continue;
            }
            ++$found[$key];
            if ($found[$key] > 1) {
                throw new \InvalidArgumentException(sprintf('the route %s is in the task list twice', $key));
            }
            if ($task->failClass !== null) {
                throw new \InvalidArgumentException(sprintf('the route %s was not prepared in the recorded run (%s)', $key, $task->failClass));
            }

            $point = self::pointOf($planner, $task, $scale);
            $id = $point->key();
            if (!isset($groups[$id])) {
                $groups[$id] = [
                    'key' => $id,
                    'tag' => $point->tag,
                    'scale' => $point->scale,
                    'recipe' => $point->recipe,
                    'build_args' => [
                        '--scale=' . $point->scale,
                        '--mode=' . $mode,
                        '--recipe-json=' . json_encode($point->recipe, JSON_THROW_ON_ERROR),
                        ...($profile === null ? [] : ['--profile=' . $profile]),
                    ],
                    'routes' => [],
                ];
            } elseif ($groups[$id]['recipe'] !== $point->recipe) {
                throw new \LogicException(sprintf('the data plan %s is asked for with two different recipes', $id));
            }

            $request = new MeasureRequest(
                $point->tag,
                $point->scale,
                'N',
                $task->spec->uri,
                (string) $task->uri,
                $task->spec->method,
                $task->actAs,
                $task->body,
                $task->form,
                $task->csrf,
                $task->headers,
                false,
                [Attempt::WARMUP, Attempt::COLD],
                $task->query,
            );
            $groups[$id]['routes'][] = ['route' => $task->spec->uri, 'method' => $task->spec->method, 'uri' => (string) $task->uri, 'measure_args' => $request->toArguments()];
        }

        foreach ($found as $key => $count) {
            if ($count === 0) {
                throw new \InvalidArgumentException(sprintf('the route %s is not in the task list', $key));
            }
        }

        return array_values(array_map(static function (array $group) use ($stateChanging): array {
            unset($group['recipe']);
            $group['routes'] = RouteOrder::lastRoutesLast($group['routes'], static fn (array $r): string => $r['method'] . ' ' . $r['route'], array_keys($stateChanging));
            // the tables to ANALYZE between two laps: those the state-changing routes of this group rewrite
            $restore = [];
            foreach ($group['routes'] as $r) {
                foreach ($stateChanging[$r['method'] . ' ' . $r['route']] ?? [] as $table) {
                    $restore[$table] = true;
                }
            }
            $group['restore_tables'] = array_keys($restore);

            return $group;
        }, $groups));
    }

    /**
     * Writes a plan as plain files a shell script can read without a JSON tool:
     *
     *   <dir>/plan.json, <dir>/plan.groups (the number of databases)
     *   <dir>/g<G>/plan.meta        key, tag and scale of database G, tab separated
     *   <dir>/g<G>/plan.build_args  the arguments of build-db, one per line
     *   <dir>/g<G>/plan.routes      per route: number, method, route, uri, a name safe for a directory, tab separated
     *   <dir>/g<G>/plan.r<R>.args   the arguments of measure-one for route R, one per line
     *
     * @param list<array{key: string, tag: string, scale: int, build_args: list<string>, routes: list<array{route: string, method: string, uri: string, measure_args: list<string>}>}> $plan
     */
    public static function writeFiles(array $plan, string $dir): void
    {
        file_put_contents($dir . '/plan.json', json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
        file_put_contents($dir . '/plan.groups', count($plan) . "\n");
        foreach (array_values($plan) as $g => $group) {
            $gd = $dir . '/g' . ($g + 1);
            if (!is_dir($gd) && !mkdir($gd, 0777, true)) {
                throw new \RuntimeException('cannot make ' . $gd);
            }
            chmod($gd, 0777); // the runner, as another user than the container's, writes its records here too
            file_put_contents($gd . '/plan.meta', $group['key'] . "\t" . $group['tag'] . "\t" . $group['scale'] . "\n");
            file_put_contents($gd . '/plan.build_args', implode("\n", $group['build_args']) . "\n");
            $routes = '';
            foreach (array_values($group['routes']) as $r => $route) {
                file_put_contents($gd . '/plan.r' . ($r + 1) . '.args', implode("\n", $route['measure_args']) . "\n");
                $routes .= implode("\t", [$r + 1, $route['method'], $route['route'], $route['uri'], preg_replace('/[^A-Za-z0-9]/', '_', $route['method'] . ' ' . $route['route'])]) . "\n";
            }
            file_put_contents($gd . '/plan.routes', $routes);
            file_put_contents($gd . '/plan.restore', implode("\n", $group['restore_tables'] ?? []) . (($group['restore_tables'] ?? []) === [] ? '' : "\n"));
        }
    }

    private static function pointOf(DataPlanner $planner, RouteTask $task, int $scale): DatabasePoint
    {
        foreach ($planner->sweepsFor($task->spec, [$scale]) as $sweep) {
            if ($sweep->axis !== 'N') {
                continue;
            }
            foreach ($sweep->points as $point) {
                if ($point->scale === $scale) {
                    return $point;
                }
            }
        }

        throw new \InvalidArgumentException(sprintf('the planner has no database of %d rows for %s', $scale, $task->spec->key()));
    }
}
