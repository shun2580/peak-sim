<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Route;

/**
 * One route to hit: where it is, how to reach it, and what the static analysis said about it.
 *
 * `uri` is exactly the string the static analysis printed (with a leading slash, parameters in braces), so a result
 * keyed by it lines up with `fixtures/expected.json` and with the analysis output. `method` is the one method the
 * route is hit with: GET when the route has it (HEAD is never hit), otherwise the first listed.
 *
 * `parameters` is name => {optional, where, model, field, type, scope}:
 *   where  the route's `where` constraint (a regular expression body), or null
 *   model  the model class when the parameter is an implicit binding, or null
 *   field  the binding column when it is not the route key (`{post:slug}`), or null
 *   type   the declared scalar type of the controller argument (`int`, `string`), or null
 *   scope  {parent, fk} when the binding is scoped to another parameter's row, or null
 *
 * `static` carries what the comparison needs: query/memory (growth, lower_bound, confidence), n_table, m_table,
 * m_per, n_table_alternatives.
 */
final class RouteSpec
{
    /**
     * @param list<string> $methods every method the route answers, without HEAD
     * @param list<string> $middleware
     * @param array<string, array{optional: bool, where: ?string, model: ?string, field: ?string, type: ?string, scope: ?array{parent: string, fk: string}}> $parameters
     * @param array<string, mixed> $static
     */
    public function __construct(
        public readonly string $uri,
        public readonly string $method,
        public readonly array $methods,
        public readonly array $middleware,
        public readonly array $parameters,
        public readonly ?string $action,
        public readonly array $static,
        public readonly bool $inRuntime,
    ) {
    }

    public function key(): string
    {
        return $this->method . ' ' . $this->uri;
    }
}
