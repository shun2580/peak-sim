<?php

declare(strict_types=1);

namespace PeakSim\Tests\Acceptance\Support;

use PeakSim\Tests\Support\ExpectationFixtureRows;
use PeakSim\Tests\Support\MiniAppWorkspace;

/**
 * Builds copies of the fixture app in which a call on the path of one route can no longer be followed.
 *
 * Variants:
 *   start  `\vendor_unknown_fn();` as the first statement of the route's action;
 *   call   the first call into the app inside the action replaced by `\vendor_unknown_fn()`;
 *   deep   a call in the deepest method reachable from the action replaced the same way (needs a callee).
 */
final class GapInjection
{
    public const VARIANTS = ['start', 'call', 'deep'];

    private static ?SourceMutator $mutator = null;

    /** @var array<string, array{0: string, 1: string}>|null */
    private static ?array $actions = null;

    /** @return array<string, array{0: string, 1: string}> route => [class, method], for the routes of expected.json */
    public static function actions(): array
    {
        if (self::$actions === null) {
            $rows = json_decode(MiniAppWorkspace::read(MiniAppWorkspace::fixtureExport(), 'route-list.json'), true, 512, JSON_THROW_ON_ERROR);
            $byUri = [];
            foreach ($rows as $row) {
                $byUri['/' . ltrim($row['uri'], '/')] = $row['action'];
            }
            self::$actions = [];
            foreach (array_keys(ExpectationFixtureRows::rows()) as $uri) {
                if (isset($byUri[$uri]) && $byUri[$uri] !== 'Closure') {
                    self::$actions[$uri] = SourceMutator::splitAction($byUri[$uri]);
                }
            }
        }

        return self::$actions;
    }

    /** @return array{file: string, source: string}|null the edit of a variant for a route, or null when it does not apply */
    public static function edit(string $uri, string $variant): ?array
    {
        [$class, $method] = self::actions()[$uri];
        $mutator = self::$mutator ??= new SourceMutator(MiniAppWorkspace::fixtureApp());

        return match ($variant) {
            'start' => $mutator->atMethodStart($class, $method, SourceMutator::UNKNOWN_CALL . ';'),
            'call' => $mutator->replaceAppCall($class, $method),
            'deep' => (static function () use ($mutator, $class, $method): ?array {
                $edit = $mutator->replaceDeepestCall($class, $method);

                return $edit !== null && $edit['depth'] >= 2 ? $edit : null;
            })(),
        };
    }

    /** @return list<string> the routes for which a variant applies */
    public static function routesFor(string $variant): array
    {
        return array_values(array_filter(array_keys(self::actions()), static fn (string $uri): bool => self::edit($uri, $variant) !== null));
    }

    /** A copy of the fixture app with the edit applied. The caller removes it with MiniAppWorkspace::remove(). */
    public static function copyWith(string $uri, string $variant): string
    {
        $edit = self::edit($uri, $variant) ?? throw new \LogicException("variant {$variant} does not apply to {$uri}");
        $copy = MiniAppWorkspace::copyApp(MiniAppWorkspace::fixtureApp(), 'gap');
        MiniAppWorkspace::write($copy, $edit['file'], $edit['source']);

        return $copy;
    }
}
