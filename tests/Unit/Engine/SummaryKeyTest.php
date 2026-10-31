<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Engine;

use PeakSim\Model\EventKind;

/** e-summary-key-eager-arg and the reuse of a summary (design 1.7). */
final class SummaryKeyTest extends EngineCase
{
    private const HELPER = <<<'PHP'
        <?php
        namespace App;
        use App\Models\Order;
        class Names
        {
            public function of(Order $order)
            {
                return $order->customer->name;
            }
        }
        PHP;

    public function testTheSameMethodWithAnEagerLoadedArgumentIsAnotherSummary(): void
    {
        $summary = $this->analyze([
            'app/Names.php' => self::HELPER,
            'app/Act.php' => <<<'PHP'
                <?php
                namespace App;
                use App\Models\Order;
                class Act
                {
                    public function __construct(private readonly Names $names) {}
                    public function run()
                    {
                        $this->names->of(Order::first());
                        $this->names->of(Order::with('customer')->first());
                    }
                }
                PHP,
        ], 'App\Act', 'run');

        self::assertCount(1, $this->events($summary, EventKind::LazyLoad), 'only the call whose argument was not eager loaded reads the relation lazily');
        self::assertNotNull($this->engine);
        self::assertSame(3, $this->engine->stats()['summaries_computed'], 'Act::run, Names::of(lazy), Names::of(eager)');
        $this->assertNoUnknownWithoutGap();
    }

    public function testTheSameCallTwiceIsWalkedOnceAndCountedTwice(): void
    {
        $summary = $this->analyze([
            'app/Names.php' => self::HELPER,
            'app/Act.php' => <<<'PHP'
                <?php
                namespace App;
                use App\Models\Order;
                class Act
                {
                    public function __construct(private readonly Names $names) {}
                    public function run()
                    {
                        $this->names->of(Order::first());
                        $this->names->of(Order::first());
                    }
                }
                PHP,
        ], 'App\Act', 'run');

        self::assertNotNull($this->engine);
        self::assertSame(1, $this->engine->stats()['summaries_reused']);
        self::assertSame(2, $this->engine->stats()['summaries_computed'] - 0);
        self::assertCount(2, $this->events($summary, EventKind::LazyLoad), 'two calls, two lazy loads');
        self::assertSame([0, 1, 2, 3], array_map(static fn ($e) => $e->seq, $summary->events), 'events are numbered in discovery order');
    }

    public function testACallInsideALoopCarriesTheLoopIntoTheCalleesEvents(): void
    {
        $summary = $this->analyze([
            'app/Names.php' => self::HELPER,
            'app/Act.php' => <<<'PHP'
                <?php
                namespace App;
                use App\Models\Order;
                class Act
                {
                    public function __construct(private readonly Names $names) {}
                    public function run()
                    {
                        foreach (Order::get() as $order) {
                            $this->names->of($order);
                        }
                    }
                }
                PHP,
        ], 'App\Act', 'run');

        $lazy = $this->events($summary, EventKind::LazyLoad);
        self::assertCount(1, $lazy);
        self::assertSame('orders', $lazy[0]->frames[0]->origin->table);
        self::assertSame('app/Names.php', $lazy[0]->site->file, 'the event keeps its own site');
        self::assertSame(['query' => 1, 'memory' => 1], $this->degrees($summary));
    }

    public function testTheModelFromALoopKeepsItsOriginSoACalleeSeesWhichTableItCameFrom(): void
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
                        $items = [];
                        foreach (Order::get() as $order) {
                            $items[] = $order;
                        }
                        foreach ($items as $o) {
                            $o->customer;
                        }
                    }
                }
                PHP,
        ], 'App\Act', 'run');

        $lazy = $this->events($summary, EventKind::LazyLoad);
        self::assertCount(1, $lazy);
        self::assertSame('orders', $lazy[0]->frames[0]->origin->table, 'the array collected from a loop over orders is as long as orders');
    }
}
