<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Query;

use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Model\Axis;
use PeakSim\Model\Cardinality;
use PeakSim\Model\Delivery;

/** Writes: insert / upsert / update / delete / save / create / createMany / firstOrCreate / updateOrCreate. */
final class WriteTest extends QueryCase
{
    private function customerIds(): ListT
    {
        return new ListT(new PlainT(), Cardinality::table('customers'), Delivery::Materialized);
    }

    public function testInsertWithALiteralIsOneStatement(): void
    {
        $w = $this->walker();
        $w->run('Order::insert([["total" => 1], ["total" => 2]])');

        $this->assertSame(['write orders q=1'], $this->events($w));
        $this->assertClean($w);
    }

    public function testInsertOfAListFromAnotherTableCarriesThatTablesSizeAsPayload(): void
    {
        $w = $this->walker(['ids' => $this->customerIds()]);
        $w->run('Order::insert($ids)');

        $this->assertSame(['write orders q=1', 'payload customers m=1:customers:payload'], $this->events($w));
        $this->assertClean($w);
    }

    public function testPluckThenInsertKeepsTheOriginAllTheWay(): void
    {
        $w = $this->walker();
        $w->vars['ids'] = $w->run('Customer::pluck("id")');
        $w->run('Order::insert($ids)');

        $this->assertSame([
            'select customers q=1 m=1:customers:materialize',
            'write orders q=1',
            'payload customers m=1:customers:payload',
        ], $this->events($w));
        $this->assertClean($w);
    }

    public function testInsertOfAChunkInsideTheLoopOverTheChunksIsAQueryPerChunk(): void
    {
        $w = $this->walker(['chunk' => new ListT(new PlainT(), Cardinality::table('customers'), Delivery::Materialized)]);
        $w->fake->withFrame(QueryWalker::frame('customers'), fn () => $w->run('Order::insert($chunk)'));

        $this->assertSame(['write orders q=1 f=customers', 'payload customers m=1:customers:payload f=customers'], $this->events($w));
        $this->assertClean($w);
    }

    public function testUpsertAndInsertOrIgnoreAreOneStatementWithTheSamePayload(): void
    {
        $w = $this->walker(['ids' => $this->customerIds()]);
        $w->run('Order::upsert($ids, ["id"], ["total"])');
        $w->run('Order::insertOrIgnore($ids)');

        $this->assertSame([
            'write orders q=1', 'payload customers m=1:customers:payload',
            'write orders q=1', 'payload customers m=1:customers:payload',
        ], $this->events($w));
        $this->assertClean($w);
    }

    public function testBuilderUpdateAndDeleteAreOneStatement(): void
    {
        $w = $this->walker();
        $w->run('Order::where("a", 1)->update(["total" => 0])');
        $w->run('Order::where("a", 1)->delete()');
        $w->run('Order::where("a", 1)->increment("total")');

        $this->assertSame(array_fill(0, 3, 'write orders q=1'), $this->events($w));
        $this->assertClean($w);
    }

    public function testSaveUpdateAndDeleteOnAnInstanceAreOneStatementEachUnderTheLoop(): void
    {
        $w = $this->walker(['order' => new ModelT(self::M . 'Order')]);
        $w->fake->withFrame(QueryWalker::frame('orders'), function () use ($w): void {
            $w->run('$order->save()');
            $w->run('$order->update(["total" => 1])');
            $w->run('$order->delete()');
        });

        $this->assertSame(array_fill(0, 3, 'write orders q=1 f=orders'), $this->events($w));
        $this->assertSame([], $this->gapCodes($w));
        $this->assertClean($w);
    }

    public function testCreateIsOneStatementAndReturnsTheModel(): void
    {
        $w = $this->walker();
        $t = $w->run('Order::create(["total" => 1])');

        $this->assertSame(['write orders q=1'], $this->events($w));
        $this->assertInstanceOf(ModelT::class, $t);
        $this->assertSame(self::M . 'Order', $t->class);
        $this->assertClean($w);
    }

    public function testCreateManyRunsOneStatementPerElementOfItsList(): void
    {
        $w = $this->walker(['customer' => new ModelT(self::M . 'Customer'), 'rows' => new ListT(new PlainT(), Cardinality::table('products'), Delivery::Materialized)]);
        $w->run('$customer->orders()->createMany($rows)');

        $this->assertSame(['write orders q=1 f=products'], $this->events($w));
        $this->assertSame([], $this->gapCodes($w));
        $this->assertClean($w);
    }

    public function testCreateManyOfALiteralIsBounded(): void
    {
        $w = $this->walker(['customer' => new ModelT(self::M . 'Customer')]);
        $w->run('$customer->orders()->createMany([["total" => 1], ["total" => 2]])');

        $this->assertSame(['write orders q=1'], $this->events($w));
        $this->assertClean($w);
    }

    public function testCreateManyOfAListWhoseSizeIsNotKnownIsAGap(): void
    {
        $w = $this->walker(['customer' => new ModelT(self::M . 'Customer'), 'rows' => new PlainT()]);
        $w->run('$customer->orders()->createMany($rows)');

        $this->assertSame(['LOOP_SOURCE_UNKNOWN'], $this->gapCodes($w));
        $this->assertClean($w);
    }

    public function testSaveManyIsPerElementToo(): void
    {
        $w = $this->walker(['order' => new ModelT(self::M . 'Order'), 'rows' => new ListT(new ModelT(self::M . 'Item'), Cardinality::table('items'), Delivery::Materialized)]);
        $w->run('$order->items()->saveMany($rows)');

        $this->assertSame(['write items q=1 f=items'], $this->events($w));
        $this->assertClean($w);
    }

    public function testFirstOrCreateAndUpdateOrCreateAreTwoConditionalStatements(): void
    {
        $w = $this->walker();
        $first = $w->run('Order::firstOrCreate(["a" => 1])');
        $update = $w->run('Order::updateOrCreate(["a" => 1], ["b" => 2])');

        $this->assertSame(['write orders q=2c', 'write orders q=2c'], $this->events($w));
        $this->assertInstanceOf(ModelT::class, $first);
        $this->assertInstanceOf(ModelT::class, $update);
        $this->assertClean($w);
    }

    public function testFirstOrNewOnlyReads(): void
    {
        $w = $this->walker();
        $t = $w->run('Order::firstOrNew(["a" => 1])');

        $this->assertSame(['select orders q=1'], $this->events($w));
        $this->assertInstanceOf(ModelT::class, $t);
        $this->assertClean($w);
    }

    public function testAModelWithObserversIsAGapOnTheWritesThatFireEvents(): void
    {
        $w = $this->walker(['saved' => new ModelT(self::M . 'ObservedOrder')], [self::M . 'ObservedOrder' => ['App\\Observers\\OrderObserver']]);
        $w->run('ObservedOrder::create(["a" => 1])');
        $w->run('$saved->save()');
        $w->run('ObservedOrder::firstOrCreate(["a" => 1])');

        $this->assertSame(['MODEL_OBSERVER_NOT_ANALYZED', 'MODEL_OBSERVER_NOT_ANALYZED', 'MODEL_OBSERVER_NOT_ANALYZED'], $this->gapCodes($w));
        $this->assertSame(['write orders q=1', 'write orders q=1', 'write orders q=2c'], $this->events($w));
        $this->assertClean($w);
    }

    public function testAModelWithRegisteredEventsIsAGapToo(): void
    {
        $w = $this->walker(['saved' => new ModelT(self::M . 'ObservedOrder')], [], [], [self::M . 'ObservedOrder' => ['saved']]);
        $w->run('ObservedOrder::updateOrCreate(["a" => 1])');
        $w->run('$saved->delete()');

        $this->assertSame(['MODEL_OBSERVER_NOT_ANALYZED', 'MODEL_OBSERVER_NOT_ANALYZED'], $this->gapCodes($w));
        $this->assertSame(
            [[Axis::Query, Axis::Memory], [Axis::Query, Axis::Memory]],
            array_map(static fn ($g): array => $g->affects, $w->fake->gaps()),
        );
        $this->assertClean($w);
    }

    public function testWritesThatFireNoModelEventsNeedNoObserverGap(): void
    {
        $w = $this->walker([], [self::M . 'ObservedOrder' => ['App\\Observers\\OrderObserver']]);
        $w->run('ObservedOrder::insert([["a" => 1]])');
        $w->run('ObservedOrder::where("a", 1)->update(["b" => 1])');

        $this->assertSame([], $this->gapCodes($w));
        $this->assertClean($w);
    }

    public function testNoObserversNoGap(): void
    {
        $w = $this->walker();
        $w->run('Order::create(["a" => 1])');

        $this->assertSame([], $this->gapCodes($w));
    }
}
