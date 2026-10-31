<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Query;

use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Model\Axis;
use PeakSim\Model\Delivery;

/** chunk / chunkById / each / lazy / lazyById / cursor: reads in windows, and the cursor's driver rule (D20). */
final class StreamingTest extends QueryCase
{
    public function testChunkIsAWindowedReadInAStreamedFrame(): void
    {
        $w = $this->walker();
        $t = $w->run('Order::chunk(100, function ($orders) { __probe($orders); Customer::count(); })');

        $this->assertSame(['select orders q=1U/100@orders', 'aggregate customers q=1 f=orders~'], $this->events($w));
        $this->assertInstanceOf(PlainT::class, $t);
        $window = $w->probes[0];
        $this->assertInstanceOf(ListT::class, $window);
        $this->assertTrue($window->window);
        $this->assertSame('orders', $window->card->origin?->table);
        $this->assertSame(Delivery::Materialized, $window->delivery);
        $this->assertSame([], $this->gapCodes($w));
        $this->assertClean($w);
    }

    public function testChunkByIdIsTheSameAsChunk(): void
    {
        $w = $this->walker();
        $w->run('Order::chunkById(200, function ($orders) { __probe($orders); })');

        $this->assertSame(['select orders q=1U/200@orders'], $this->events($w));
        $this->assertTrue($w->probes[0]->window);
        $this->assertClean($w);
    }

    public function testChunkSizeThatIsNotAConstantIsLeftOut(): void
    {
        $w = $this->walker(['size' => new PlainT()]);
        $w->run('Order::chunk($size, function ($orders) { })');

        $this->assertSame(['select orders q=1U@orders'], $this->events($w));
        $this->assertClean($w);
    }

    public function testEachHandsOverOneRowAtATimeInAStreamedFrame(): void
    {
        $w = $this->walker();
        $w->run('Order::each(function ($order) { __probe($order); Customer::count(); })');

        $this->assertSame(['select orders q=1U/1000@orders', 'aggregate customers q=1 f=orders~'], $this->events($w));
        $this->assertInstanceOf(ModelT::class, $w->probes[0]);
        $this->assertClean($w);
    }

    public function testChunkWithACallbackThatIsNotAClosureIsAGap(): void
    {
        $w = $this->walker(['callback' => new PlainT()]);
        $w->run('Order::chunk(100, $callback)');

        $this->assertSame(['UNRESOLVED_CALL'], $this->gapCodes($w));
        $this->assertClean($w);
    }

    public function testChunkLoadsTheEagerRelationsPerWindow(): void
    {
        $w = $this->walker();
        $w->run('Order::with("items")->chunk(100, function ($orders) { })');

        $this->assertSame(['select orders q=1U/100@orders', 'eager_load items q=1U@orders m=1:items/orders:eager_to_many'], $this->events($w));
        $this->assertClean($w);
    }

    public function testLazyDefaultsTo1000AndStreams(): void
    {
        $w = $this->walker();
        $default = $w->run('Order::lazy()');
        $sized = $w->run('Order::lazy(100)');
        $byId = $w->run('Order::lazyById(200)');

        $this->assertSame(['select orders q=1U/1000@orders', 'select orders q=1U/100@orders', 'select orders q=1U/200@orders'], $this->events($w));
        foreach ([$default, $sized, $byId] as $t) {
            $this->assertListOfTable($t, 'Order', 'orders', Delivery::Streamed);
        }
        $this->assertClean($w);
    }

    public function testCursorOnMysqlBuffersEveryRow(): void
    {
        $w = $this->walker();
        $t = $w->run('Order::cursor()');

        $this->assertSame(['pdo_buffer orders q=1 m=1:orders:pdo_buffered'], $this->events($w));
        $this->assertListOfTable($t, 'Order', 'orders', Delivery::Streamed);
        $this->assertSame([], $this->assumptionCodes($w));
        $this->assertClean($w);
    }

    public function testCursorOnMariadbBuffersEveryRow(): void
    {
        $w = $this->walker([], [], [self::M . 'Order' => 'mariadb']);
        $w->run('Order::cursor()');

        $this->assertSame(['pdo_buffer orders q=1 m=1:orders:pdo_buffered'], $this->events($w));
        $this->assertSame([], $this->assumptionCodes($w));
    }

    public function testCursorOnSqliteDoesNotBuffer(): void
    {
        $w = $this->walker([], [], [self::M . 'Order' => 'sqlite']);
        $w->run('Order::cursor()');

        $this->assertSame(['pdo_buffer orders q=1 m=0:orders:pdo_buffered'], $this->events($w));
        $this->assertSame([], $this->assumptionCodes($w));
        $this->assertSame([], $this->gapCodes($w));
    }

    public function testCursorOnAnUnknownDriverIsAssumedToBufferAndSaysSo(): void
    {
        $w = $this->walker([], [], [self::M . 'Order' => 'pgsql']);
        $w->run('Order::cursor()');

        $this->assertSame(['pdo_buffer orders q=1 m=1:orders:pdo_buffered'], $this->events($w));
        $this->assertSame(['DRIVER_ASSUMED'], $this->assumptionCodes($w));
        $this->assertSame([Axis::Memory], $w->fake->assumptions()[0]->affects);
    }

    public function testCursorDoesNotEagerLoadSoRelationsStayLazy(): void
    {
        $w = $this->walker();
        $t = $w->run('WithOrder::with("items")->cursor()');

        $this->assertSame(['pdo_buffer orders q=1 m=1:orders:pdo_buffered'], $this->events($w));
        $this->assertInstanceOf(ModelT::class, $t->elem);
        $this->assertSame([], $t->elem->eager);
        $this->assertClean($w);
    }
}
