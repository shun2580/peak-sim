<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Engine;

use PeakSim\Analysis\Contracts\CallHandler;
use PeakSim\Analysis\Contracts\CallSite;
use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Contracts\HandlerRegistry;
use PeakSim\Analysis\Engine\Engine;
use PeakSim\Analysis\Engine\EngineOptions;
use PeakSim\Analysis\Type\ClosureT;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Model\Axis;
use PeakSim\Model\Cardinality;
use PeakSim\Model\Delivery;
use PeakSim\Model\EventKind;
use PeakSim\Model\Frame;
use PeakSim\Model\GapCode;
use PeakSim\Model\Origin;
use PeakSim\Source\AppSourceIndex;
use PeakSim\Tests\Unit\Engine\Support\FakeBindingTable;
use PeakSim\Tests\Unit\Engine\Support\FakeModelCatalog;
use PeakSim\Tests\Unit\Engine\Support\StubKnownHandler;
use PeakSim\Tests\Unit\Engine\Support\StubQueryHandler;
use PeakSim\Tests\Unit\Engine\Support\TempApp;
use PhpParser\Node\Expr;

/** e-for-gap, e-while-gap, e-loop-plain-array, e-loop-source-unknown-gap and the frames of foreach (design 1.5). */
final class LoopsTest extends EngineCase
{
    public function testAForLoopWhoseBodyReadsTheDatabaseIsAGap(): void
    {
        $summary = $this->invokable(<<<'PHP'
                for ($i = 0; $i < 3; $i++) {
                    Order::first();
                }
        PHP);

        self::assertSame([GapCode::LOOP_BOUND_UNKNOWN], $this->gapCodes($summary));
        self::assertCount(1, $this->events($summary), 'what was found is still reported');
    }

    public function testAWhileLoopWhoseBodyReadsTheDatabaseIsAGap(): void
    {
        $summary = $this->invokable(<<<'PHP'
                $n = 0;
                while ($n < 10) {
                    Order::first();
                    $n++;
                }
        PHP);

        self::assertSame([GapCode::LOOP_BOUND_UNKNOWN], $this->gapCodes($summary));
    }

    public function testADoWhileLoopWhoseBodyReadsTheDatabaseIsAGap(): void
    {
        $summary = $this->invokable(<<<'PHP'
                $n = 0;
                do {
                    Order::first();
                    $n++;
                } while ($n < 10);
        PHP);

        self::assertSame([GapCode::LOOP_BOUND_UNKNOWN], $this->gapCodes($summary));
    }

    public function testAForOrWhileLoopWithNothingInItIsNotAGap(): void
    {
        $summary = $this->invokable(<<<'PHP'
                $total = 0;
                for ($i = 0; $i < 3; $i++) {
                    $total += $i;
                }
                while ($total > 0) {
                    $total--;
                }
        PHP);

        self::assertSame([], $summary->gaps);
        self::assertSame([], $summary->events);
    }

    public function testALoopOverALiteralArrayMakesNoFrameAndNoGap(): void
    {
        $summary = $this->invokable(<<<'PHP'
                foreach ([1, 2, 3] as $id) {
                    Order::first();
                }
        PHP);

        self::assertSame([], $summary->gaps);
        self::assertSame([], $this->events($summary)[0]->frames);
        self::assertSame(['query' => 0, 'memory' => 0], $this->degrees($summary));
    }

    public function testALoopOverAClassConstantArrayIsBounded(): void
    {
        $summary = $this->analyze(['app/Http/Controllers/C.php' => <<<'PHP'
            <?php
            namespace App\Http\Controllers;
            use App\Models\Order;
            class C
            {
                private const KINDS = ['a', 'b', 'c'];
                public function __invoke()
                {
                    foreach (self::KINDS as $kind) {
                        Order::first();
                    }
                }
            }
            PHP], 'App\Http\Controllers\C');

        self::assertSame([], $summary->gaps);
        self::assertSame([], $this->events($summary)[0]->frames);
    }

    public function testALoopOverAValueOfUnknownSizeWithAQueryInItIsAGap(): void
    {
        $summary = $this->analyze(['app/Http/Controllers/C.php' => <<<'PHP'
            <?php
            namespace App\Http\Controllers;
            use App\Models\Order;
            class C
            {
                public function __invoke(array $ids)
                {
                    foreach ($ids as $id) {
                        Order::find($id);
                    }
                }
            }
            PHP], 'App\Http\Controllers\C');

        self::assertSame([GapCode::LOOP_SOURCE_UNKNOWN], $this->gapCodes($summary));
        self::assertSame([], $this->events($summary)[0]->frames, 'no frame is invented for a loop of unknown size');
    }

    public function testALoopOverAValueOfUnknownSizeWithNothingInItIsIgnored(): void
    {
        $summary = $this->analyze(['app/Http/Controllers/C.php' => <<<'PHP'
            <?php
            namespace App\Http\Controllers;
            class C
            {
                public function __invoke(array $ids)
                {
                    $sum = 0;
                    foreach ($ids as $id) {
                        $sum += $id;
                    }
                    return $sum;
                }
            }
            PHP], 'App\Http\Controllers\C');

        self::assertSame([], $summary->gaps);
    }

    public function testNestedLoopsOverTablesGiveTwoFramesOuterFirst(): void
    {
        $summary = $this->invokable(<<<'PHP'
                foreach (Order::get() as $order) {
                    foreach ($order->items as $item) {
                        $item->product;
                    }
                }
        PHP);

        $lazy = $this->events($summary, EventKind::LazyLoad);
        $product = array_values(array_filter($lazy, static fn ($e) => $e->table === 'products'));
        self::assertCount(1, $product);
        self::assertSame(['orders', 'items'], array_map(static fn (Frame $f) => $f->origin->table, $product[0]->frames));
        self::assertSame('orders', $product[0]->frames[1]->origin->perTable, 'M is items per order');
        self::assertSame(2, $this->degrees($summary)['query']);
    }

    public function testALoopInsideACalleeGetsTheCallersFramesInFrontOfItsOwn(): void
    {
        $summary = $this->analyze([
            'app/Act.php' => <<<'PHP'
                <?php
                namespace App;
                use App\Models\Order;
                class Act
                {
                    public function run()
                    {
                        foreach (Order::get() as $order) {
                            $this->inner($order);
                        }
                    }
                    private function inner($order)
                    {
                        foreach ($order->items as $item) {
                            $item->product;
                        }
                    }
                }
                PHP,
        ], 'App\Act', 'run');

        $product = array_values(array_filter($this->events($summary, EventKind::LazyLoad), static fn ($e) => $e->table === 'products'));
        self::assertSame(['orders', 'items'], array_map(static fn (Frame $f) => $f->origin->table, $product[0]->frames));
    }

    public function testAClosureCallbackGetsTheElementTypeAndTheFrame(): void
    {
        $summary = $this->invokable(<<<'PHP'
                Order::get()->each(function ($o) {
                    $o->customer;
                });
        PHP);

        $lazy = $this->events($summary, EventKind::LazyLoad);
        self::assertCount(1, $lazy);
        self::assertSame('orders', $lazy[0]->frames[0]->origin->table);
        $this->assertNoUnknownWithoutGap();
    }

    public function testAClosureCapturesTheVariablesItUses(): void
    {
        $summary = $this->invokable(<<<'PHP'
                $first = Order::with('customer')->first();
                Order::get()->each(function ($o) use ($first) {
                    $first->customer;
                    $o->customer;
                });
        PHP);

        $lazy = $this->events($summary, EventKind::LazyLoad);
        self::assertCount(1, $lazy, 'the captured eager loaded model reads its customer for free; the loop element does not');
    }

    public function testACallbackOverABoundedListMakesNoFrame(): void
    {
        $summary = $this->invokable(<<<'PHP'
                $first = Order::first();
                $both = [$first, $first];
                foreach ($both as $o) {
                    $o->customer;
                }
        PHP);

        $lazy = $this->events($summary, EventKind::LazyLoad);
        self::assertSame([], $lazy[0]->frames);
    }

    public function testTheWindowOfAChunkIsNotLoopedAgain(): void
    {
        $registry = new HandlerRegistry();
        $registry->register(new StubQueryHandler(), 10);
        $registry->register(new StubKnownHandler(), 30);
        $registry->register(new class () implements CallHandler {
            public function handles(CallSite $c, EvalContext $ctx): bool
            {
                return $c->name === 'chunk_orders';
            }

            public function handle(CallSite $c, EvalContext $ctx): Type
            {
                $callback = $c->argType(0);
                assert($callback instanceof ClosureT);
                $origin = new Origin('orders');
                $window = new ListT(new ModelT('App\Models\Order'), Cardinality::table('orders'), Delivery::Streamed, true);

                return $ctx->withFrame(new Frame($origin, true, $ctx->site($c->node)), fn (): Type => $ctx->invokeClosure($callback, [$window]));
            }
        }, 5);
        $app = new TempApp(['app/Act.php' => <<<'PHP'
            <?php
            namespace App;
            class Act
            {
                public function run()
                {
                    chunk_orders(function ($orders) {
                        foreach ($orders as $order) {
                            $order->customer;
                        }
                    });
                }
            }
            PHP]);
        $engine = new Engine(
            new AppSourceIndex($app->root),
            new FakeModelCatalog(['App\Models\Order' => ['table' => 'orders', 'relations' => ['customer' => ['App\Models\Customer', false]]], 'App\Models\Customer' => ['table' => 'customers']]),
            new FakeBindingTable(),
            $registry,
            new EngineOptions(auditUnknown: true),
        );

        $summary = $engine->analyzeAction('App\Act', 'run');

        $lazy = $this->events($summary, EventKind::LazyLoad);
        self::assertCount(1, $lazy);
        self::assertCount(1, $lazy[0]->frames, 'N/c chunks times c rows is N, not N squared');
        self::assertTrue($lazy[0]->frames[0]->streamed);
        self::assertSame(['query' => 1, 'memory' => 0], $this->degrees($summary), 'a streamed loop adds to the queries and not to the memory');
        self::assertSame([], $engine->violations());
    }

    public function testCollectingRowsInsideAStreamedLoopCountsAsMemory(): void
    {
        $registry = new HandlerRegistry();
        $registry->register(new StubKnownHandler(), 30);
        $registry->register(new class () implements CallHandler {
            public function handles(CallSite $c, EvalContext $ctx): bool
            {
                return $c->name === 'stream_orders';
            }

            public function handle(CallSite $c, EvalContext $ctx): Type
            {
                $callback = $c->argType(0);
                assert($callback instanceof ClosureT);
                $window = new ListT(new ModelT('App\Models\Order'), Cardinality::table('orders'), Delivery::Streamed, true);

                return $ctx->withFrame(new Frame(new Origin('orders'), true, $ctx->site($c->node)), fn (): Type => $ctx->invokeClosure($callback, [$window]));
            }
        }, 5);
        $app = new TempApp(['app/Act.php' => <<<'PHP'
            <?php
            namespace App;
            class Act
            {
                public function run()
                {
                    $kept = [];
                    stream_orders(function ($orders) use (&$kept) {
                        foreach ($orders as $order) {
                            $kept[] = $order;
                        }
                    });
                }
            }
            PHP]);
        $engine = new Engine(
            new AppSourceIndex($app->root),
            new FakeModelCatalog(['App\Models\Order' => ['table' => 'orders']]),
            new FakeBindingTable(),
            $registry,
            new EngineOptions(auditUnknown: true),
        );

        $summary = $engine->analyzeAction('App\Act', 'run');

        // $kept is captured by reference, so it was declared outside the stream: the append is one level inside it.
        $accumulate = $this->events($summary, EventKind::Accumulate);
        self::assertCount(1, $accumulate);
        self::assertSame(0, $accumulate[0]->memory?->retainFrom);
        self::assertSame(['query' => 0, 'memory' => 1], $this->degrees($summary));
        self::assertSame([Axis::Memory], $summary->assumptions[0]->affects);
        self::assertSame('ACCUMULATION_HEURISTIC', $summary->assumptions[0]->code->value);
    }
}
