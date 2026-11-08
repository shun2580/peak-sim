<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Explain;

use PeakSim\Runtime\Measure\DataPlanner;
use PeakSim\Runtime\Measure\RouteTask;
use PeakSim\Runtime\Variance\TrialPlan;

/**
 * Which databases `explain-at-scale` builds, and which routes it asks each one about.
 *
 * It takes the data plan of every route from the recorded task list the same way the driver and `variance-run` do
 * (`TrialPlan`), at the target scale and at the small scale, so a route is explained on the database the driver would have
 * measured it on. A route that is not in the task list, or was not prepared in the recorded run, is not planned: it is listed
 * with the reason (`explain_unavailable` for all its statements).
 */
final class ExplainPlan
{
    public const ROLE_TARGET = 'target';

    public const ROLE_SMALL = 'small';

    /**
     * @param list<RouteTask> $tasks
     * @param list<string>    $routeKeys "METHOD uri" of the routes the capture holds
     *
     * @return array{groups: list<array{role: string, key: string, tag: string, scale: int, build_args: list<string>, routes: list<string>}>, unplanned: array<string, string>}
     */
    public static function build(array $tasks, array $routeKeys, int $scale, int $smallScale, DataPlanner $planner, string $mode, ?string $profile): array
    {
        $byKey = [];
        foreach ($tasks as $task) {
            $byKey[$task->spec->key()][] = $task;
        }
        $plannable = [];
        $unplanned = [];
        foreach (array_values(array_unique($routeKeys)) as $key) {
            $found = $byKey[$key] ?? [];
            if ($found === []) {
                $unplanned[$key] = 'route_not_in_the_task_list';
            } elseif (count($found) > 1) {
                $unplanned[$key] = 'route_in_the_task_list_twice';
            } elseif ($found[0]->failClass !== null) {
                $unplanned[$key] = 'route_not_prepared_in_the_recorded_run: ' . $found[0]->failClass;
            } else {
                $plannable[] = $key;
            }
        }

        $groups = [];
        if ($plannable !== []) {
            foreach ([[self::ROLE_TARGET, $scale], [self::ROLE_SMALL, $smallScale]] as [$role, $s]) {
                foreach (TrialPlan::build($tasks, $plannable, $s, $planner, $mode, $profile) as $group) {
                    $groups[] = [
                        'role' => $role,
                        'key' => $group['key'],
                        'tag' => $group['tag'],
                        'scale' => $group['scale'],
                        'build_args' => $group['build_args'],
                        'routes' => array_map(static fn (array $r): string => $r['method'] . ' ' . $r['route'], $group['routes']),
                    ];
                }
            }
        }

        return ['groups' => $groups, 'unplanned' => $unplanned];
    }

    /**
     * The plan as plain files for a shell script (no JSON tool is needed on the host):
     *
     *   <dir>/plan.json
     *   <dir>/plan.groups          the number of databases
     *   <dir>/g<G>/plan.meta       role, tag and scale of database G, tab separated
     *   <dir>/g<G>/plan.build_args the arguments of build-db, one per line
     *   <dir>/g<G>/plan.routes     the routes of database G, one per line
     *
     * @param array{groups: list<array<string, mixed>>, unplanned: array<string, string>} $plan
     */
    public static function writeFiles(array $plan, string $dir): void
    {
        file_put_contents($dir . '/plan.json', json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
        file_put_contents($dir . '/plan.groups', count($plan['groups']) . "\n");
        foreach (array_values($plan['groups']) as $g => $group) {
            $gd = $dir . '/g' . ($g + 1);
            if (!is_dir($gd) && !mkdir($gd, 0777, true)) {
                throw new \RuntimeException('cannot make ' . $gd);
            }
            chmod($gd, 0777);
            file_put_contents($gd . '/plan.meta', $group['role'] . "\t" . $group['tag'] . "\t" . $group['scale'] . "\n");
            file_put_contents($gd . '/plan.build_args', implode("\n", $group['build_args']) . "\n");
            file_put_contents($gd . '/plan.routes', implode("\n", $group['routes']) . "\n");
        }
    }
}
