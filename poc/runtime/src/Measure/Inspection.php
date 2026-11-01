<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

use PeakSim\Runtime\Route\ArgumentResolver;
use PeakSim\Runtime\Route\BodyBuilder;
use PeakSim\Runtime\Route\RouteCatalog;

/**
 * The inspection of a run: with the app started on the inspection database, every route of the static analysis becomes
 * a task (filled URI, login, body) or a recorded reason it cannot be one. The app's profile, when the run has one,
 * reaches the three places that use it: where an argument's value comes from (`arguments`), why one cannot be prepared
 * (`unfillable`), and who the first request is made as (`user`, `admin`, `login_as`).
 */
final class Inspection
{
    /**
     * @param object               $app      Illuminate\Foundation\Application
     * @param array<string, mixed> $analysis the static analysis output
     * @param array<string, mixed> $profile  the app's profile (empty when none was asked for)
     *
     * @return array{tasks: list<array<string, mixed>>, skipped: int}
     */
    public static function tasks(object $app, array $analysis, Options $options, array $profile = []): array
    {
        $catalog = RouteCatalog::build($analysis, RouteTable::rows($app));
        $lookup = new EloquentRowLookup($app);
        $builder = new TaskBuilder(new ArgumentResolver($lookup, $profile), new BodyBuilder($lookup), new LaravelAppFacts($app), $profile);

        $tasks = [];
        foreach ($catalog->specs() as $spec) {
            if ($options->wants($spec->uri)) {
                $tasks[] = $builder->build($spec)->toArray();
            }
        }

        return ['tasks' => $tasks, 'skipped' => $catalog->skippedCount()];
    }
}
