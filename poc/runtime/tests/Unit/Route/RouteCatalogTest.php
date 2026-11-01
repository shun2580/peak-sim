<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Route;

use PeakSim\Runtime\Route\RouteCatalog;
use PHPUnit\Framework\TestCase;

final class RouteCatalogTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function analysis(): array
    {
        $axis = static fn (string $g, ?string $lb = null, ?string $c = null): array => ['growth' => $g, 'degree' => null, 'confidence' => $c, 'lower_bound' => $lb];

        return ['routes' => [
            ['route' => '/ok/eager', 'methods' => ['GET', 'HEAD'], 'action' => 'App\\Http\\Controllers\\OkController@eager', 'kind' => 'controller',
                'query' => $axis('O(1)', null, 'high'), 'memory' => $axis('O(N)'), 'n_table' => 'orders', 'm_table' => null, 'm_per' => null, 'n_table_alternatives' => []],
            ['route' => '/orders/{order}', 'methods' => ['PUT', 'PATCH'], 'action' => 'App\\Http\\Controllers\\OrderController@update', 'kind' => 'controller',
                'query' => $axis('unknown', 'O(N)'), 'memory' => $axis('unknown'), 'n_table' => 'orders', 'm_table' => 'items', 'm_per' => 'orders', 'n_table_alternatives' => ['customers']],
            ['route' => '/files/{path?}', 'methods' => ['GET', 'HEAD'], 'action' => 'App\\Http\\Controllers\\FileController@show', 'kind' => 'controller',
                'query' => $axis('unknown'), 'memory' => $axis('unknown'), 'n_table' => null, 'm_table' => null, 'm_per' => null, 'n_table_alternatives' => []],
            ['route' => '/closure', 'methods' => ['GET'], 'action' => 'Closure', 'kind' => 'closure',
                'query' => $axis('unknown'), 'memory' => $axis('unknown'), 'n_table' => null, 'm_table' => null, 'm_per' => null, 'n_table_alternatives' => []],
            ['route' => '/gone', 'methods' => ['GET'], 'action' => 'App\\Http\\Controllers\\GoneController', 'kind' => 'controller',
                'query' => $axis('unknown'), 'memory' => $axis('unknown'), 'n_table' => null, 'm_table' => null, 'm_per' => null, 'n_table_alternatives' => []],
        ]];
    }

    /** @return list<array<string, mixed>> */
    private static function runtime(): array
    {
        return [
            ['uri' => 'ok/eager', 'methods' => ['GET', 'HEAD'], 'middleware' => ['web'], 'action' => 'App\\Http\\Controllers\\OkController@eager'],
            ['uri' => 'orders/{order}', 'methods' => ['PUT', 'PATCH'], 'middleware' => ['web', 'auth'], 'action' => 'App\\Http\\Controllers\\OrderController@update',
                'wheres' => ['order' => '[0-9]+'], 'parameters' => ['order' => ['model' => 'App\\Models\\Order', 'field' => null, 'type' => null]]],
            ['uri' => 'files/{path?}', 'methods' => ['GET', 'HEAD'], 'middleware' => [], 'action' => 'App\\Http\\Controllers\\FileController@show'],
            ['uri' => 'only/in/runtime', 'methods' => ['GET'], 'middleware' => [], 'action' => 'X@y'],
        ];
    }

    public function test_only_controller_routes_are_catalogued_and_the_rest_is_counted(): void
    {
        $catalog = RouteCatalog::build(self::analysis(), self::runtime());

        $this->assertSame(['/ok/eager', '/orders/{order}', '/files/{path?}', '/gone'], array_map(static fn ($s) => $s->uri, $catalog->specs()));
        $this->assertSame(1, $catalog->skippedCount());
    }

    public function test_the_uri_stays_exactly_as_the_static_analysis_printed_it(): void
    {
        $spec = RouteCatalog::build(self::analysis(), self::runtime())->specs()[0];

        $this->assertSame('/ok/eager', $spec->uri);
    }

    public function test_a_route_with_get_and_head_is_hit_with_get_and_head_is_dropped(): void
    {
        $spec = RouteCatalog::build(self::analysis(), self::runtime())->specs()[0];

        $this->assertSame('GET', $spec->method);
        $this->assertSame(['GET'], $spec->methods);
    }

    public function test_a_put_patch_route_is_hit_with_the_first_write_method(): void
    {
        $spec = RouteCatalog::build(self::analysis(), self::runtime())->specs()[1];

        $this->assertSame('PUT', $spec->method);
        $this->assertSame(['PUT', 'PATCH'], $spec->methods);
        $this->assertSame('PUT /orders/{order}', $spec->key());
    }

    public function test_runtime_middleware_and_wheres_and_bindings_are_attached(): void
    {
        $spec = RouteCatalog::build(self::analysis(), self::runtime())->specs()[1];

        $this->assertSame(['web', 'auth'], $spec->middleware);
        $this->assertSame('[0-9]+', $spec->parameters['order']['where']);
        $this->assertSame('App\\Models\\Order', $spec->parameters['order']['model']);
        $this->assertFalse($spec->parameters['order']['optional']);
        $this->assertTrue($spec->inRuntime);
    }

    public function test_parameters_come_from_the_uri_even_without_runtime_detail(): void
    {
        $spec = RouteCatalog::build(self::analysis(), self::runtime())->specs()[2];

        $this->assertSame(['path'], array_keys($spec->parameters));
        $this->assertTrue($spec->parameters['path']['optional']);
        $this->assertNull($spec->parameters['path']['model']);
    }

    public function test_the_static_judgement_is_carried_along_for_the_comparison(): void
    {
        $specs = RouteCatalog::build(self::analysis(), self::runtime())->specs();

        $this->assertSame('O(1)', $specs[0]->static['query']['growth']);
        $this->assertSame('high', $specs[0]->static['query']['confidence']);
        $this->assertSame('O(N)', $specs[1]->static['query']['lower_bound']);
        $this->assertSame('orders', $specs[1]->static['n_table']);
        $this->assertSame('items', $specs[1]->static['m_table']);
        $this->assertSame('orders', $specs[1]->static['m_per']);
        $this->assertSame(['customers'], $specs[1]->static['n_table_alternatives']);
    }

    public function test_a_static_route_the_runtime_does_not_have_is_kept_and_flagged(): void
    {
        $catalog = RouteCatalog::build(self::analysis(), self::runtime());

        $gone = $catalog->specs()[3];
        $this->assertFalse($gone->inRuntime);
        $this->assertSame(['GET /gone'], array_map(static fn ($s) => $s->key(), $catalog->notInRuntime()));
    }

    public function test_a_runtime_route_the_static_analysis_does_not_know_is_ignored(): void
    {
        $uris = array_map(static fn ($s) => $s->uri, RouteCatalog::build(self::analysis(), self::runtime())->specs());

        $this->assertNotContains('/only/in/runtime', $uris);
    }

    public function test_the_same_uri_with_different_methods_matches_by_method(): void
    {
        $analysis = ['routes' => [
            ['route' => '/things', 'methods' => ['POST'], 'action' => 'A@store', 'kind' => 'controller', 'query' => ['growth' => 'unknown', 'lower_bound' => null, 'confidence' => null],
                'memory' => ['growth' => 'unknown'], 'n_table' => null, 'm_table' => null, 'm_per' => null],
        ]];
        $runtime = [
            ['uri' => 'things', 'methods' => ['GET', 'HEAD'], 'middleware' => ['read'], 'action' => 'A@index'],
            ['uri' => 'things', 'methods' => ['POST'], 'middleware' => ['write'], 'action' => 'A@store'],
        ];

        $spec = RouteCatalog::build($analysis, $runtime)->specs()[0];

        $this->assertSame(['write'], $spec->middleware);
    }

    public function test_the_root_route_matches(): void
    {
        $analysis = ['routes' => [
            ['route' => '/', 'methods' => ['GET', 'HEAD'], 'action' => 'A@home', 'kind' => 'controller', 'query' => ['growth' => 'unknown', 'lower_bound' => null, 'confidence' => null],
                'memory' => ['growth' => 'unknown'], 'n_table' => null, 'm_table' => null, 'm_per' => null],
        ]];

        $spec = RouteCatalog::build($analysis, [['uri' => '/', 'methods' => ['GET', 'HEAD'], 'middleware' => ['web'], 'action' => 'A@home']])->specs()[0];

        $this->assertTrue($spec->inRuntime);
    }
}
