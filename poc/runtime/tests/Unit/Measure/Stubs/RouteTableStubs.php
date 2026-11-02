<?php

declare(strict_types=1);

/*
 * Stand-ins for what RouteTable reads: an Eloquent model class, controllers, and a router that returns plain routes.
 * The runtime unit suite has no Laravel, so the route object is a duck-typed fake of Illuminate\Routing\Route.
 */

namespace Illuminate\Database\Eloquent {
    if (!class_exists(Model::class, false)) {
        abstract class Model
        {
        }
    }
}

namespace PeakSim\Runtime\Tests\Unit\Measure\Stubs {
    use Illuminate\Database\Eloquent\Model;

    final class Album extends Model
    {
    }

    final class InvokableAlbumController
    {
        public function __invoke(Album $album, int $page): void
        {
        }
    }

    final class AlbumController
    {
        public function show(Album $album): void
        {
        }
    }

    /** A controller with neither the registered method nor __invoke. */
    final class EmptyController
    {
    }

    /** Has __invoke, but the route names another method that does not exist (apiResource registers all seven). */
    final class InvokableWithoutDestroyController
    {
        public function __invoke(Album $album): void
        {
        }
    }

    /** What `Illuminate\Routing\Route` gives RouteTable. For an invokable action the action name has no '@' and `getActionMethod()` returns the class name. */
    final class FakeRoute
    {
        public array $wheres = [];

        /** @param list<string> $names */
        public function __construct(
            private readonly string $uri,
            private readonly string $actionName,
            private readonly array $names,
            private readonly array $middleware = ['web'],
            private readonly array $excluded = [],
        ) {
        }

        public function uri(): string
        {
            return $this->uri;
        }

        public function methods(): array
        {
            return ['GET', 'HEAD'];
        }

        /** As Laravel's: what the route declares, group and alias names unexpanded. */
        public function gatherMiddleware(): array
        {
            return $this->middleware;
        }

        public function excludedMiddleware(): array
        {
            return $this->excluded;
        }

        public function getActionName(): string
        {
            return $this->actionName;
        }

        public function parameterNames(): array
        {
            return $this->names;
        }

        public function getControllerClass(): ?string
        {
            return explode('@', $this->actionName)[0];
        }

        public function getActionMethod(): string
        {
            $parts = explode('@', $this->actionName);

            return end($parts);
        }

        public function bindingFieldFor(string $name): ?string
        {
            return null;
        }
    }

    /** A router that lists its routes and nothing else (what the table was read from before it learned to expand). */
    class FakeRouterWithoutExpansion
    {
        /** @param list<FakeRoute> $routes */
        public function __construct(protected readonly array $routes)
        {
        }

        public function getRoutes(): object
        {
            $routes = $this->routes;

            return new class ($routes) {
                public function __construct(private readonly array $routes)
                {
                }

                public function getRoutes(): array
                {
                    return $this->routes;
                }
            };
        }
    }

    /**
     * The app's own definitions: group name => members, alias => class. Members may be groups again (Laravel allows it),
     * and an alias may carry parameters ('auth:api'), which stay after the expanded name.
     */
    final class FakeRouterMiddleware
    {
        /**
         * @param array<string, list<string>> $groups
         * @param array<string, string>       $aliases
         */
        public function __construct(public readonly array $groups = [], public readonly array $aliases = [])
        {
        }
    }

    /** The two methods of Illuminate\Routing\Router that expand a route's middleware, with the same rules as Laravel's. */
    final class FakeRouter extends FakeRouterWithoutExpansion
    {
        /** @param list<FakeRoute> $routes */
        public function __construct(array $routes, private readonly FakeRouterMiddleware $definitions)
        {
            parent::__construct($routes);
        }

        public function gatherRouteMiddleware(FakeRoute $route): array
        {
            return $this->resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware());
        }

        /**
         * @param list<string> $middleware
         * @param list<string> $excluded
         *
         * @return list<string>
         */
        public function resolveMiddleware(array $middleware, array $excluded = []): array
        {
            $excluded = array_merge(...array_map(fn (string $n): array => $this->expand($n), $excluded) ?: [[]]);
            $out = [];
            foreach ($middleware as $name) {
                foreach ($this->expand($name) as $resolved) {
                    if (!in_array($resolved, $excluded, true)) {
                        $out[] = $resolved;
                    }
                }
            }

            return $out;
        }

        /** @return list<string> */
        private function expand(string $name): array
        {
            if (isset($this->definitions->groups[$name])) {
                return array_merge(...array_map(fn (string $n): array => $this->expand($n), $this->definitions->groups[$name]));
            }
            [$base, $parameters] = array_pad(explode(':', $name, 2), 2, null);
            if (isset($this->definitions->aliases[$base])) {
                return [$this->definitions->aliases[$base] . ($parameters === null ? '' : ':' . $parameters)];
            }

            return [$name];
        }
    }

    final class FakeApp implements \ArrayAccess
    {
        /**
         * @param list<FakeRoute>                                  $routes
         * @param FakeRouterMiddleware|null $middleware how the app's router expands names; null = a router that cannot
         */
        public function __construct(private readonly array $routes, private readonly ?FakeRouterMiddleware $middleware = null)
        {
        }

        public function offsetExists(mixed $offset): bool
        {
            return $offset === 'router';
        }

        public function offsetGet(mixed $offset): object
        {
            $routes = $this->routes;

            return $this->middleware === null
                ? new FakeRouterWithoutExpansion($routes)
                : new FakeRouter($routes, $this->middleware);
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
        }

        public function offsetUnset(mixed $offset): void
        {
        }
    }
}
