<?php

namespace Tests\Feature\Hidden;

use App\Hidden\Contracts\OrderSummaries;
use App\Hidden\Providers\ConditionalBindingServiceProvider;
use App\Hidden\Services\EagerOrderSummaries;
use App\Hidden\Services\LazyOrderSummaries;
use Closure;

/**
 * /hidden/conditional-binding: the binding picks an eager-loading or an N+1 implementation by environment.
 * Expected: queries O(N) (the worst case among the candidates is adopted), memory O(N).
 */
class ConditionalBindingTest extends HiddenRouteTestCase
{
    public function test_the_binding_follows_the_environment(): void
    {
        $this->app['env'] = 'production';
        $this->assertInstanceOf(EagerOrderSummaries::class, $this->app->make(OrderSummaries::class));

        $this->app['env'] = 'testing';
        $this->assertInstanceOf(LazyOrderSummaries::class, $this->app->make(OrderSummaries::class));
    }

    public function test_both_implementations_return_the_same_rows(): void
    {
        $this->seedOrders(4);

        $this->bind(EagerOrderSummaries::class)();
        $eager = $this->get('/hidden/conditional-binding')->assertOk()->assertJsonCount(4)->getContent();
        $this->bind(LazyOrderSummaries::class)();
        $lazy = $this->get('/hidden/conditional-binding')->assertOk()->getContent();

        $this->assertSame($eager, $lazy);
    }

    public function test_queries_are_constant_with_eager_loading_and_linear_with_n_plus_one_and_the_route_is_the_worst_case(): void
    {
        $results = $this->measure('/hidden/conditional-binding', [10, 100, 1000], $this->candidates());

        $this->assertQueryClass('O(1)', $results, 'eager');
        $this->assertQueryClass('O(N)', $results, 'lazy');
        $this->assertQueryClass('O(N)', $results, 'adopted');

        $this->bind(LazyOrderSummaries::class)();
        $this->assertQueryGrowthAtSmallScales('/hidden/conditional-binding (lazy)', 'O(N)', run: fn () => $this->get('/hidden/conditional-binding')->assertOk(), atMostPerRow: 1);
        $this->bind(EagerOrderSummaries::class)();
        $this->assertQueryGrowthAtSmallScales('/hidden/conditional-binding (eager)', 'O(1)', run: fn () => $this->get('/hidden/conditional-binding')->assertOk());
    }

    public function test_memory_grows_with_the_number_of_orders(): void
    {
        $results = $this->measure('/hidden/conditional-binding', [100, 1000, 10000], $this->candidates());

        $this->assertMemoryClass('O(N)', $results, 'adopted');
        $this->assertMemoryClass('O(N)', $results, 'eager');
        $this->assertMemoryClass('O(N)', $results, 'lazy');
    }

    /** @return array<string, array{work: Closure, prepare: Closure}> */
    private function candidates(): array
    {
        $get = fn () => $this->get('/hidden/conditional-binding')->assertOk();

        return [
            // The binding the provider chooses under the testing environment.
            'adopted' => ['prepare' => fn () => $this->app->bind(OrderSummaries::class, ConditionalBindingServiceProvider::byEnvironment(...)), 'work' => $get],
            'eager' => ['prepare' => $this->bind(EagerOrderSummaries::class), 'work' => $get],
            'lazy' => ['prepare' => $this->bind(LazyOrderSummaries::class), 'work' => $get],
        ];
    }

    private function bind(string $implementation): Closure
    {
        return fn () => $this->app->bind(OrderSummaries::class, $implementation);
    }
}
