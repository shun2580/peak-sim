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
        foreach ($app['router']->getRoutes()->getRoutes() as $route) {
            $names = $route->parameterNames();
            $rows[] = [
                'uri' => $route->uri(),
                'methods' => $route->methods(),
                'middleware' => array_values(array_filter($route->gatherMiddleware(), 'is_string')),
                'action' => $route->getActionName(),
                'wheres' => array_map('strval', $route->wheres),
                'parameters' => $names === [] ? [] : self::parameters($route, $names),
            ];
        }

        return $rows;
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
