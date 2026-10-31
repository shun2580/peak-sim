<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Query;

use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\BuilderT;
use PeakSim\Model\CardinalityKind;

/** with / load / without / withCount, the relation lazy loads, and the model's own $with (D25). */
final class EagerLoadTest extends QueryCase
{
    private function order(array $eager = []): ModelT
    {
        return new ModelT(self::M . 'Order', $eager);
    }

    public function testWithAddsOneQueryPerRelationAtTheTerminal(): void
    {
        $w = $this->walker();
        $t = $w->run('Order::with("customer")->get()');

        $this->assertSame(['select orders q=1 m=1:orders:materialize', 'eager_load customers q=1'], $this->events($w));
        $this->assertSame(['customer'], $t->elem->eager);
        $this->assertSame([], $this->gapCodes($w));
        $this->assertClean($w);
    }

    public function testEagerToManyAddsOneToTheMemoryDegree(): void
    {
        $w = $this->walker();
        $w->run('Order::with("items")->get()');

        $this->assertSame(['select orders q=1 m=1:orders:materialize', 'eager_load items q=1 m=2:items/orders:eager_to_many'], $this->events($w));
        $this->assertClean($w);
    }

    public function testEagerToManyOnOneRowOrOnABoundedReadIsDegreeOne(): void
    {
        $w = $this->walker();
        $w->run('Order::with("items")->first()');
        $w->run('Order::with("items")->limit(10)->get()');

        $this->assertSame([
            'select orders q=1', 'eager_load items q=1 m=1:items/orders:eager_to_many',
            'select orders q=1', 'eager_load items q=1 m=1:items/orders:eager_to_many',
        ], $this->events($w));
        $this->assertClean($w);
    }

    public function testWithAcceptsArraysAndVariadics(): void
    {
        $w = $this->walker();
        $array = $w->run('Order::with(["customer", "items"])->first()');
        $variadic = $w->run('Order::with("customer", "items")->first()');

        $this->assertSame(['customer', 'items'], $array->eager);
        $this->assertSame(['customer', 'items'], $variadic->eager);
        $this->assertCount(6, $this->events($w));
        $this->assertClean($w);
    }

    public function testDottedWithLoadsEachLevelOnce(): void
    {
        $w = $this->walker();
        $t = $w->run('Order::with("items.product")->get()');

        $this->assertSame([
            'select orders q=1 m=1:orders:materialize',
            'eager_load items q=1 m=2:items/orders:eager_to_many',
            'eager_load products q=1',
        ], $this->events($w));
        $this->assertSame(['items', 'items.product'], $t->elem->eager);
        $this->assertClean($w);
    }

    public function testWithClosureRunsTheConstraintOnTheRelatedBuilderAndCountsOneQuery(): void
    {
        $w = $this->walker();
        $w->run('Order::with(["items" => fn ($q) => __probe($q)])->get()');

        $this->assertSame(['select orders q=1 m=1:orders:materialize', 'eager_load items q=1 m=2:items/orders:eager_to_many'], $this->events($w));
        $probe = $w->probes[0];
        $this->assertInstanceOf(BuilderT::class, $probe);
        $this->assertSame(self::M . 'Item', $probe->class);
        $this->assertSame([], $this->gapCodes($w));
        $this->assertClean($w);
    }

    public function testColumnSelectionAfterAColonIsNotPartOfTheName(): void
    {
        $w = $this->walker();
        $t = $w->run('Order::with("customer:id,name")->get()');

        $this->assertSame(['customer'], $t->elem->eager);
        $this->assertSame([], $this->gapCodes($w));
        $this->assertClean($w);
    }

    public function testWithNamesThatAreNotConstantAreAGap(): void
    {
        $w = $this->walker(['rel' => new PlainT()]);
        $w->run('Order::with($rel)->get()');

        $this->assertSame(['RELATION_TARGET_UNKNOWN'], $this->gapCodes($w));
        $this->assertClean($w);
    }

    public function testAnUnknownRelationNameIsAGapNotASilentNothing(): void
    {
        $w = $this->walker();
        $w->run('Order::with("nope")->get()');

        $this->assertSame(['RELATION_TARGET_UNKNOWN'], $this->gapCodes($w));
        $this->assertClean($w);
    }

    public function testWithoutRemovesWhatTheModelLoadsOnItsOwn(): void
    {
        $w = $this->walker();
        $t = $w->run('WithOrder::without("customer")->get()');

        $this->assertSame(['select orders q=1 m=1:orders:materialize'], $this->events($w));
        $this->assertSame([], $t->elem->eager);
        $this->assertClean($w);
    }

    public function testWithCountAndFriendsAddAnAttributeAndNoQuery(): void
    {
        $w = $this->walker();
        foreach (['withCount("items")', 'withSum("items", "quantity")', 'withAvg("items", "quantity")', 'withMin("items", "quantity")', 'withMax("items", "quantity")', 'withExists("items")'] as $call) {
            $t = $w->run("Order::{$call}->get()");
            $this->assertSame([], $t->elem->eager, $call);
        }

        $this->assertSame(array_fill(0, 6, 'select orders q=1 m=1:orders:materialize'), $this->events($w));
        $this->assertClean($w);
    }

    public function testTheCountAttributeIsAPlainValueNotARelation(): void
    {
        $w = $this->walker(['order' => $this->order()]);
        $t = $w->run('$order->items_count');

        $this->assertInstanceOf(PlainT::class, $t);
        $this->assertSame([], $this->events($w));
        $this->assertClean($w);
    }

    public function testLoadAfterTheFactLoadsOneRelationAndMarksItLoaded(): void
    {
        $w = $this->walker(['order' => $this->order()]);
        $loaded = $w->run('$order->load("items")');
        $items = $w->run('$order->load("items")->items');

        $this->assertSame(['eager_load items q=1 m=1:items/orders:eager_to_many', 'eager_load items q=1 m=1:items/orders:eager_to_many'], $this->events($w));
        $this->assertSame(['items'], $loaded->eager);
        $this->assertInstanceOf(ListT::class, $items);
        $this->assertClean($w);
    }

    public function testLoadMissingSkipsWhatIsAlreadyLoaded(): void
    {
        $w = $this->walker(['loaded' => $this->order(['items']), 'bare' => $this->order()]);
        $w->run('$loaded->loadMissing("items")');
        $this->assertSame([], $this->events($w));

        $t = $w->run('$bare->loadMissing("items", "customer")');
        $this->assertSame(['eager_load items q=1 m=1:items/orders:eager_to_many', 'eager_load customers q=1'], $this->events($w));
        $this->assertSame(['customer', 'items'], $t->eager);
        $this->assertClean($w);
    }

    public function testLazyLoadOfToOneIsOneQueryPerAccessUnderTheLoop(): void
    {
        $w = $this->walker(['order' => $this->order()]);
        $t = $w->fake->withFrame(QueryWalker::frame('orders'), fn () => $w->run('$order->customer'));

        $this->assertSame(['lazy_load customers q=1 f=orders'], $this->events($w));
        $this->assertInstanceOf(ModelT::class, $t);
        $this->assertSame(self::M . 'Customer', $t->class);
        $this->assertClean($w);
    }

    public function testLazyLoadOfToManyAddsOneToTheMemoryDegree(): void
    {
        $w = $this->walker(['order' => $this->order()]);
        $t = $w->fake->withFrame(QueryWalker::frame('orders'), fn () => $w->run('$order->items'));

        $this->assertSame(['lazy_load items q=1 m=1:items/orders:lazy_to_many f=orders'], $this->events($w));
        $this->assertListOfTable($t, 'Item', 'items', per: 'orders');
        $this->assertClean($w);
    }

    public function testAnEagerLoadedRelationDoesNotLoadAgain(): void
    {
        $w = $this->walker(['order' => $this->order(['customer', 'items', 'items.product'])]);
        $customer = $w->run('$order->customer');
        $items = $w->run('$order->items');

        $this->assertSame([], $this->events($w));
        $this->assertInstanceOf(ModelT::class, $customer);
        $this->assertSame(['product'], $items->elem->eager);
        $this->assertClean($w);
    }

    public function testRelationsOfAModelTheExportDoesNotListAreMarkedInferred(): void
    {
        $w = $this->walker(['order' => new ModelT(self::M . 'InferredOrder')]);
        $w->run('$order->customer');

        $this->assertSame(['RELATION_INFERRED'], array_values(array_unique($this->assumptionCodes($w))));
        $this->assertClean($w);
    }

    public function testAttributeThatIsAnAccessorRunsTheAccessorBody(): void
    {
        $w = $this->walker(['order' => $this->order()]);
        $w->methodResults[self::M . 'Order::getCustomerNameAttribute'] = new PlainT();
        $w->run('$order->customer_name');

        $this->assertSame([[self::M . 'Order', 'getCustomerNameAttribute', []]], $w->methodCalls);
        $this->assertSame([], $this->events($w));
        $this->assertClean($w);
    }

    public function testPlainAttributeIsPlain(): void
    {
        $w = $this->walker(['order' => $this->order()]);
        $t = $w->run('$order->total');

        $this->assertInstanceOf(PlainT::class, $t);
        $this->assertSame([], $w->methodCalls);
        $this->assertSame([], $this->events($w));
    }

    public function testModelWithOneWithAddsOneQuery(): void
    {
        $w = $this->walker();
        $t = $w->run('WithOrder::get()');

        $this->assertSame(['select orders q=1 m=1:orders:materialize', 'eager_load customers q=1'], $this->events($w));
        $this->assertSame(['customer'], $t->elem->eager);
        $this->assertSame([], $this->gapCodes($w));
        $this->assertSame([], $this->assumptionCodes($w));
        $this->assertClean($w);
    }

    public function testModelWithTwoWithsAddsTwoQueries(): void
    {
        $w = $this->walker();
        $w->run('TwoWithOrder::get()');

        $this->assertSame([
            'select orders q=1 m=1:orders:materialize',
            'eager_load customers q=1',
            'eager_load items q=1 m=2:items/orders:eager_to_many',
        ], $this->events($w));
        $this->assertClean($w);
    }

    public function testTheWithOfTheModelAppliesToAFirstToo(): void
    {
        $w = $this->walker();
        $w->run('WithOrder::first()');

        $this->assertSame(['select orders q=1', 'eager_load customers q=1'], $this->events($w));
        $this->assertClean($w);
    }

    public function testTheWithOfTheModelAppliesToALazyLoadOfThatModel(): void
    {
        $w = $this->walker(['item' => new ModelT(self::M . 'Item')]);
        $t = $w->run('$item->withOrder');

        $this->assertSame(['lazy_load orders q=1', 'eager_load customers q=1'], $this->events($w));
        $this->assertSame(['customer'], $t->eager);
        $this->assertClean($w);
    }

    public function testTheWithOfTheRelatedModelRidesAlongWithAnExplicitWith(): void
    {
        $w = $this->walker();
        $t = $w->run('Item::with("withOrder")->get()');

        $this->assertSame([
            'select items q=1 m=1:items:materialize',
            'eager_load orders q=1',
            'eager_load customers q=1',
        ], $this->events($w));
        $this->assertSame(['withOrder', 'withOrder.customer'], $t->elem->eager);
        $this->assertClean($w);
    }

    public function testTheWithOfTheModelIsLoadedInTheSameWayForAnotherModel(): void
    {
        $w = $this->walker();
        $t = $w->run('ItemWithProduct::get()');

        $this->assertSame(['select items q=1 m=1:items:materialize', 'eager_load products q=1'], $this->events($w));
        $this->assertSame(['product'], $t->elem->eager);
        $this->assertClean($w);
    }

    public function testACycleInWithIsCutAndSaid(): void
    {
        $w = $this->walker();
        $w->run('CycleOrder::get()');

        $this->assertSame(['WITH_CYCLE'], array_values(array_unique($this->gapCodes($w))));
        $this->assertSame([
            'select orders q=1 m=1:orders:materialize',
            'eager_load customers q=1',
            'eager_load orders q=1 m=2:orders/customers:eager_to_many',
        ], $this->events($w));
        $this->assertClean($w);
    }

    public function testAWithThatIsNotAConstantListIsAGapBecauseItAddsQueriesWeCannotCount(): void
    {
        $w = $this->walker();
        $w->run('DynamicWithOrder::get()');

        $this->assertContains('RELATION_TARGET_UNKNOWN', $this->gapCodes($w));
        $this->assertClean($w);
    }

    public function testEagerLoadingOfTheTerminalIsNotRepeatedOnAPaginatedRead(): void
    {
        $w = $this->walker();
        $w->run('Order::with("customer")->paginate(10)');

        $this->assertSame(['select orders q=2', 'eager_load customers q=1'], $this->events($w));
        $this->assertClean($w);
    }

    public function testAnEagerLoadedListKeepsItsSizeOrigin(): void
    {
        $w = $this->walker();
        $t = $w->run('Order::with("customer")->get()');

        $this->assertSame(CardinalityKind::Table, $t->card->kind);
        $this->assertSame('orders', $t->card->origin?->table);
    }
}
