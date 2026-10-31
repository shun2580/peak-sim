<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Query;

use PeakSim\Analysis\Type\BuilderT;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\UnknownT;
use PeakSim\Model\CardinalityKind;
use PeakSim\Model\Delivery;

/** Relation builders (`$order->items()`), scopes, unknown methods, and the DB facade. */
final class RelationAndDbTest extends QueryCase
{
    private function order(): ModelT
    {
        return new ModelT(self::M . 'Order');
    }

    public function testTheRelationWithParenthesesIsABuilderAndCostsNothingYet(): void
    {
        $w = $this->walker(['order' => $this->order()]);
        $t = $w->run('$order->items()');

        $this->assertInstanceOf(BuilderT::class, $t);
        $this->assertSame(self::M . 'Item', $t->class);
        $this->assertSame('items', $t->table);
        $this->assertSame([self::M . 'Order', 'items'], $t->relationOf);
        $this->assertSame([], $this->events($w));
        $this->assertClean($w);
    }

    public function testCountOnTheRelationBuilderIsAQueryPerOrderInTheLoop(): void
    {
        $w = $this->walker(['order' => $this->order()]);
        $w->fake->withFrame(QueryWalker::frame('orders'), fn () => $w->run('$order->items()->count()'));

        $this->assertSame(['aggregate items q=1 f=orders'], $this->events($w));
        $this->assertClean($w);
    }

    public function testGetOnTheRelationBuilderReadsTheItemsOfOneOrder(): void
    {
        $w = $this->walker(['order' => $this->order()]);
        $t = $w->run('$order->items()->get()');

        $this->assertSame(['select items q=1 m=1:items/orders:materialize'], $this->events($w));
        $this->assertListOfTable($t, 'Item', 'items', per: 'orders');
        $this->assertClean($w);
    }

    public function testCreateOnTheRelationBuilderWritesTheRelatedTable(): void
    {
        $w = $this->walker(['order' => $this->order()]);
        $t = $w->run('$order->items()->create(["quantity" => 1])');

        $this->assertSame(['write items q=1'], $this->events($w));
        $this->assertInstanceOf(ModelT::class, $t);
        $this->assertSame(self::M . 'Item', $t->class);
        $this->assertClean($w);
    }

    public function testAttachAndSyncWriteThePivotAndSyncReadsFirst(): void
    {
        $w = $this->walker(['order' => $this->order()]);
        $w->run('$order->tags()->attach([1, 2])');
        $w->run('$order->tags()->sync([1, 2])');
        $w->run('$order->tags()->detach()');

        $this->assertSame(['write - q=1', 'write - q=3c', 'write - q=1'], $this->events($w));
        $this->assertClean($w);
    }

    public function testAssociateTouchesNoDatabase(): void
    {
        $w = $this->walker(['order' => $this->order(), 'customer' => new ModelT(self::M . 'Customer')]);
        $w->run('$order->customer()->associate($customer)');

        $this->assertSame([], $this->events($w));
        $this->assertClean($w);
    }

    public function testScopeMethodIsFoundOnTheModelAndItsBodyIsWalked(): void
    {
        $w = $this->walker();
        $t = $w->run('Order::recent()->get()');

        $this->assertEquals([[self::M . 'Order', 'scopeRecent', [new BuilderT(self::M . 'Order', 'orders')]]], $w->methodCalls);
        $this->assertSame(['select orders q=1 m=1:orders:materialize'], $this->events($w));
        $this->assertSame(CardinalityKind::Table, $t->card->kind);
        $this->assertClean($w);
    }

    public function testScopeOnAnExistingBuilderTakesThatBuilder(): void
    {
        $w = $this->walker();
        $w->methodResults[self::M . 'Order::scopeRecent'] = (new BuilderT(self::M . 'Order', 'orders'))->withBounded();
        $t = $w->run('Order::where("a", 1)->recent()->get()');

        $this->assertSame('scopeRecent', $w->methodCalls[0][1]);
        $this->assertSame(['select orders q=1'], $this->events($w));
        $this->assertSame(CardinalityKind::Bounded, $t->card->kind);
        $this->assertClean($w);
    }

    public function testScopeThatReturnsNothingKeepsTheBuilder(): void
    {
        $w = $this->walker();
        $w->methodResults[self::M . 'Order::scopeWithBigItems'] = new PlainT();
        $t = $w->run('Order::withBigItems()->get()');

        $this->assertSame(CardinalityKind::Table, $t->card->kind);
        $this->assertClean($w);
    }

    public function testAMethodTheModelDefinesItselfIsLeftToTheEngine(): void
    {
        $w = $this->walker();
        $call = new \PeakSim\Analysis\Contracts\CallSite($w->expression('Order::newest()'), null, self::M . 'Order', 'newest', []);
        $statics = new \PeakSim\Analysis\Handlers\Query\ModelStatic();

        $this->assertFalse($statics->handles($call, $w->ctx));
        $this->assertTrue($statics->handles(new \PeakSim\Analysis\Contracts\CallSite($w->expression('Order::where()'), null, self::M . 'Order', 'where', []), $w->ctx));
    }

    public function testAnUnknownMethodOnAModelOrBuilderIsAGapNotSilence(): void
    {
        $w = $this->walker();
        $onModel = $w->run('Order::fooBar()');
        $onBuilder = $w->run('Order::where("a", 1)->fooBaz()');

        $this->assertInstanceOf(UnknownT::class, $onModel);
        $this->assertInstanceOf(UnknownT::class, $onBuilder);
        $this->assertSame(['UNKNOWN_BUILDER_METHOD', 'UNKNOWN_BUILDER_METHOD'], $this->gapCodes($w));
        $this->assertClean($w);
    }

    public function testDbTableReadsLikeAModelButReturnsPlainRows(): void
    {
        $w = $this->walker();
        $t = $w->run('DB::table("orders")->where("a", 1)->get()');

        $this->assertSame(['select orders q=1 m=1:orders:materialize'], $this->events($w));
        $this->assertInstanceOf(ListT::class, $t);
        $this->assertInstanceOf(PlainT::class, $t->elem);
        $this->assertSame('orders', $t->card->origin?->table);
        $this->assertSame(Delivery::Materialized, $t->delivery);
        $this->assertSame([], $this->assumptionCodes($w));
        $this->assertClean($w);
    }

    public function testDbTableWritesAndChunks(): void
    {
        $w = $this->walker();
        $w->run('DB::table("orders")->insert([["a" => 1]])');
        $w->run('DB::table("orders")->chunk(50, function ($rows) { __probe($rows); })');

        $this->assertSame(['write orders q=1', 'select orders q=1U/50@orders'], $this->events($w));
        $this->assertTrue($w->probes[0]->window);
        $this->assertClean($w);
    }

    public function testDbTableOfAVariableLosesTheTableAndSaysItAssumedTheWorst(): void
    {
        $w = $this->walker(['name' => new PlainT()]);
        $t = $w->run('DB::table($name)->get()');

        $this->assertSame(['select - q=1 m=1:?:materialize'], $this->events($w));
        $this->assertNull($t->card->origin?->table);
        $this->assertSame(['SQL_NOT_PARSED'], array_values(array_unique($this->assumptionCodes($w))));
        $this->assertClean($w);
    }

    public function testDbSelectOfRawSqlIsUnboundedAndNotParsed(): void
    {
        $w = $this->walker();
        $t = $w->run('DB::select("select * from orders")');

        $this->assertSame(['select - q=1 m=1:?:materialize'], $this->events($w));
        $this->assertInstanceOf(ListT::class, $t);
        $this->assertSame(CardinalityKind::Table, $t->card->kind);
        $this->assertNull($t->card->origin?->table);
        $this->assertSame(['SQL_NOT_PARSED'], $this->assumptionCodes($w));
        $this->assertClean($w);
    }

    public function testDbRawIsAPlainValueWithTheAssumptionOnRecord(): void
    {
        $w = $this->walker();
        $t = $w->run('DB::raw("count(*)")');

        $this->assertInstanceOf(PlainT::class, $t);
        $this->assertSame(['SQL_NOT_PARSED'], $this->assumptionCodes($w));
        $this->assertSame([], $this->events($w));
    }

    public function testDbTransactionRunsItsClosureOnce(): void
    {
        $w = $this->walker();
        $w->run('DB::transaction(fn () => Order::count())');

        $this->assertSame(['aggregate orders q=1'], $this->events($w));
        $this->assertClean($w);
    }

    public function testDbStatementsThatWriteAreOneStatement(): void
    {
        $w = $this->walker();
        $w->run('DB::statement("truncate orders")');
        $w->run('DB::insert("insert into orders (a) values (1)")');

        $this->assertSame(['write - q=1', 'write - q=1'], $this->events($w));
        $this->assertClean($w);
    }

    public function testDbMethodsOutsideTheListAreLeftToOthers(): void
    {
        $w = $this->walker();
        $call = new \PeakSim\Analysis\Contracts\CallSite($w->expression('DB::listen()'), null, 'Illuminate\\Support\\Facades\\DB', 'listen', []);

        $this->assertFalse((new \PeakSim\Analysis\Handlers\Query\ModelStatic())->handles($call, $w->ctx));
    }
}
