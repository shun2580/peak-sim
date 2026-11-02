<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

/**
 * The route table of the booted app, in the rows `Route\RouteCatalog::build()` takes: how to reach each route (the
 * middleware it passes, the `where` constraints) and what each `{parameter}` is (a model, a scalar).
 */
final class RouteTable
{
    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';

    private const HAS_ONE_OR_MANY = 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrMany';

    /**
     * @param object $app Illuminate\Foundation\Application
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(object $app): array
    {
        $rows = [];
        $router = $app['router'];
        foreach ($router->getRoutes()->getRoutes() as $route) {
            $names = $route->parameterNames();
            $rows[] = [
                'uri' => $route->uri(),
                'methods' => $route->methods(),
                'middleware' => self::middleware($router, $route),
                'action' => $route->getActionName(),
                'wheres' => array_map('strval', $route->wheres),
                'parameters' => $names === [] ? [] : self::parameters($route, $names),
            ];
        }

        return $rows;
    }

    /**
     * The middleware a route passes, as the app's own router resolves it: a group the app defined (`user-full-auth`) or
     * an alias is opened into the middleware it stands for, and what the route leaves out (`withoutMiddleware`) is gone.
     * Nothing is written to the app; the router is only asked.
     *
     * The names the route declares stay, first and in their order, ahead of the expanded ones. The rules that read these
     * names (what needs a login; a profile's `middleware` match such as `signed`) are written against the declared
     * names, and opening an alias such as `auth.basic` must not make them stop matching. A name left out is not kept.
     * A router that cannot expand (none of Laravel's, since 5.x) gives the declared names alone, as before.
     *
     * @return list<string>
     */
    private static function middleware(object $router, object $route): array
    {
        $declared = array_values(array_filter($route->gatherMiddleware(), 'is_string'));
        if (!method_exists($router, 'gatherRouteMiddleware')) {
            return $declared;
        }

        $excluded = method_exists($route, 'excludedMiddleware') ? array_values(array_filter($route->excludedMiddleware(), 'is_string')) : [];
        $expanded = array_values(array_filter($router->gatherRouteMiddleware($route), 'is_string'));

        return array_values(array_unique([...array_diff($declared, $excluded), ...$expanded]));
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, array<string, mixed>>
     */
    private static function parameters(object $route, array $names): array
    {
        $byName = [];
        $controller = $route->getControllerClass();
        $method = $route->getActionMethod();
        // An invokable controller has no '@method' in its action, so Laravel's getActionMethod() answers with the class
        // name. Only then is the method __invoke; a named method that does not exist (apiResource registers all seven)
        // stays without parameter types and is not given __invoke's.
        if (is_string($controller) && $method === $controller && !method_exists($controller, $method)) {
            $method = '__invoke';
        }
        if (is_string($controller) && method_exists($controller, $method)) {
            foreach ((new \ReflectionMethod($controller, $method))->getParameters() as $p) {
                $byName[$p->getName()] = $p;
            }
        }

        $out = [];
        $previousModel = null;
        foreach ($names as $name) {
            $type = null;
            $model = null;
            $t = isset($byName[$name]) ? $byName[$name]->getType() : null;
            if ($t instanceof \ReflectionNamedType) {
                if ($t->isBuiltin()) {
                    $type = $t->getName();
                } elseif (is_subclass_of($t->getName(), self::MODEL)) {
                    $model = $t->getName();
                }
            }
            $scope = null;
            if ($model !== null && $previousModel !== null && method_exists($route, 'enforcesScopedBindings') && $route->enforcesScopedBindings()) {
                $scope = self::scope($previousModel, $model, $name);
            }
            if ($model !== null) {
                $previousModel = [$name, $model];
            }
            $out[$name] = ['model' => $model, 'field' => $route->bindingFieldFor($name), 'type' => $type, 'scope' => $scope];
        }

        return $out;
    }

    /**
     * @param array{string, string} $parent [parameter name, model class]
     *
     * @return array{parent: string, fk: string}|null
     */
    private static function scope(array $parent, string $childModel, string $childName): ?array
    {
        [$parentName, $parentClass] = $parent;
        $camel = lcfirst(str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $childName))));
        foreach ([$camel . 's', $camel, lcfirst(substr($camel, 0, -1)) . 'ies'] as $relation) {
            try {
                if (!method_exists($parentClass, $relation)) {
                    continue;
                }
                $related = (new $parentClass())->{$relation}();
                if (is_a($related, self::HAS_ONE_OR_MANY) && is_a($related->getRelated(), $childModel)) {
                    return ['parent' => $parentName, 'fk' => $related->getForeignKeyName()];
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
