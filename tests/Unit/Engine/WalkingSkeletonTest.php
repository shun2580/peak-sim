<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Engine;

use PeakSim\Model\EventKind;

/** The first target: three routes of the prototype, walked by the new engine (design 2.4, gate G1). */
final class WalkingSkeletonTest extends EngineCase
{
    public function testNPlusOneInALoopOverAllOrders(): void
    {
        $summary = $this->invokable(<<<'PHP'
                $orders = Order::get();
                $rows = [];
                foreach ($orders as $order) {
                    $rows[] = ['id' => $order->id, 'customer' => $order->customer->name];
                }
                return response()->json($rows);
        PHP);

        self::assertSame([], $summary->gaps);
        self::assertCount(1, $this->events($summary, EventKind::Select));
        $lazy = $this->events($summary, EventKind::LazyLoad);
        self::assertCount(1, $lazy);
        self::assertCount(1, $lazy[0]->frames, 'the lazy load sits inside one loop over orders');
        self::assertSame('orders', $lazy[0]->frames[0]->origin->table);
        self::assertFalse($lazy[0]->frames[0]->streamed);
        self::assertSame('customers', $lazy[0]->table);
        self::assertSame(['query' => 1, 'memory' => 1], $this->degrees($summary));
        $this->assertNoUnknownWithoutGap();
    }

    public function testEagerLoadedRelationReadInsideAMapIsNotAQuery(): void
    {
        $summary = $this->invokable(<<<'PHP'
                $orders = Order::with('customer')->get();
                return response()->json($orders->map(fn (Order $order) => [
                    'id' => $order->id,
                    'customer' => $order->customer->name,
                ]));
        PHP);

        self::assertSame([], $summary->gaps);
        self::assertSame([], $this->events($summary, EventKind::LazyLoad), 'the declared Order does not hide that the element was eager loaded');
        self::assertCount(1, $this->events($summary, EventKind::EagerLoad));
        self::assertSame(['query' => 0, 'memory' => 1], $this->degrees($summary));
        $this->assertNoUnknownWithoutGap();
    }

    public function testNPlusOneHiddenFiveLayersDeepBehindAnInterface(): void
    {
        $summary = $this->analyze([
            'app/Http/Controllers/LayeredController.php' => <<<'PHP'
                <?php
                namespace App\Http\Controllers;
                use App\UseCases\ListOrderSummaries;
                class LayeredController
                {
                    public function __construct(private readonly ListOrderSummaries $useCase) {}
                    public function __invoke()
                    {
                        return response()->json(['orders' => $this->useCase->handle()]);
                    }
                }
                PHP,
            'app/UseCases/ListOrderSummaries.php' => <<<'PHP'
                <?php
                namespace App\UseCases;
                use App\Domain\OrderSummaryService;
                class ListOrderSummaries
                {
                    public function __construct(private readonly OrderSummaryService $service) {}
                    public function handle(): array
                    {
                        return $this->service->summaries();
                    }
                }
                PHP,
            'app/Domain/OrderSummaryService.php' => <<<'PHP'
                <?php
                namespace App\Domain;
                use App\Repositories\OrderRepository;
                class OrderSummaryService
                {
                    public function __construct(private readonly OrderRepository $orders) {}
                    public function summaries(): array
                    {
                        return $this->orders->all();
                    }
                }
                PHP,
            'app/Repositories/OrderRepository.php' => <<<'PHP'
                <?php
                namespace App\Repositories;
                interface OrderRepository
                {
                    public function all(): array;
                }
                PHP,
            'app/Repositories/EloquentOrderRepository.php' => <<<'PHP'
                <?php
                namespace App\Repositories;
                use App\Models\Order;
                class EloquentOrderRepository implements OrderRepository
                {
                    public function all(): array
                    {
                        $rows = [];
                        foreach (Order::get() as $order) {
                            $rows[] = ['id' => $order->id, 'customer' => $order->customer->name];
                        }
                        return $rows;
                    }
                }
                PHP,
        ], 'App\Http\Controllers\LayeredController', '__invoke', ['App\Repositories\OrderRepository' => ['App\Repositories\EloquentOrderRepository']]);

        self::assertSame([], $summary->gaps);
        $lazy = $this->events($summary, EventKind::LazyLoad);
        self::assertCount(1, $lazy);
        self::assertSame(1, count($lazy[0]->frames));
        self::assertSame('orders', $lazy[0]->frames[0]->origin->table);
        self::assertSame(['query' => 1, 'memory' => 1], $this->degrees($summary));
        self::assertSame([], $summary->assumptions, 'a single implementation needs no worst-case assumption');
        self::assertGreaterThanOrEqual(5, count($summary->trace), 'every method on the way is in the trace');
        self::assertSame(4, $this->engine?->stats()['deepest_level'], 'five classes are four call levels: the action is level 1');
        $this->assertNoUnknownWithoutGap();
    }

    public function testTheSiteOfAnEventIsAnAppRelativePath(): void
    {
        $summary = $this->invokable('        return Order::get();');

        self::assertSame('app/Http/Controllers/T.php', $summary->events[0]->site->file);
        self::assertGreaterThan(0, $summary->events[0]->site->line);
    }
}
