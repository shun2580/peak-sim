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

        public function gatherMiddleware(): array
        {
            return ['web'];
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

    final class FakeApp implements \ArrayAccess
    {
        /** @param list<FakeRoute> $routes */
        public function __construct(private readonly array $routes)
        {
        }

        public function offsetExists(mixed $offset): bool
        {
            return $offset === 'router';
        }

        public function offsetGet(mixed $offset): object
        {
            $routes = $this->routes;

            return new class ($routes) {
                public function __construct(private readonly array $routes)
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
            };
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
        }

        public function offsetUnset(mixed $offset): void
        {
        }
    }
}
