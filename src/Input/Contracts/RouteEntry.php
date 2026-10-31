<?php

declare(strict_types=1);

namespace PeakSim\Input;

/** One row of `route:list --json`. */
final readonly class RouteEntry
{
    /**
     * @param string $uri kept with a leading slash
     * @param list<string> $methods
     * @param string $action 'Class@method' | 'Class' | 'Closure'
     * @param list<string> $middleware
     */
    public function __construct(
        public string $uri,
        public array $methods,
        public ?string $name,
        public string $action,
        public array $middleware,
        public ?string $path,
        public ?string $domain,
    ) {
    }
}
