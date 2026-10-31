<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Query;

use PeakSim\Analysis\Type\BuilderT;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\UnknownT;
use PeakSim\Model\Cardinality;
use PeakSim\Model\CardinalityKind;
use PeakSim\Model\Delivery;

/** Terminals that read: get / all / first / find / pluck / paginate / limit / aggregates. */
final class ReadTest extends QueryCase
{
    public function testGetReadsTheWholeTable(): void
    {
        $w = $this->walker();
        $t = $w->run('Order::where("status", "paid")->orderBy("id")->get()');

        $this->assertSame(['select orders q=1 m=1:orders:materialize'], $this->events($w));
        $this->assertListOfTable($t, 'Order', 'orders');
        $this->assertInstanceOf(ModelT::class, $t->elem);
        $this->assertSame('orders', $t->elem->origin?->table);
        $this->assertSame([], $this->gapCodes($w));
        $this->assertClean($w);
    }

    public function testAllIsAnUnboundedGet(): void
    {
        $w = $this->walker();
        $t = $w->run('Order::all()');

        $this->assertSame(['select orders q=1 m=1:orders:materialize'], $this->events($w));
        $this->assertListOfTable($t, 'Order', 'orders');
        $this->assertClean($w);
    }

    public function testPaginateIsBoundedWithTwoStatements(): void
    {
        $w = $this->walker();
        $t = $w->run('Order::paginate(15)');

        $this->assertSame(['select orders q=2'], $this->events($w));
        $this->assertInstanceOf(ListT::class, $t);
        $this->assertSame(CardinalityKind::Bounded, $t->card->kind);
        $this->assertSame([], $this->gapCodes($w));
        $this->assertClean($w);
    }

    public function testSimplePaginateAndCursorPaginateAreBoundedWithOneStatement(): void
    {
        $w = $this->walker();
        $simple = $w->run('Order::simplePaginate(15)');
        $cursor = $w->run('Order::cursorPaginate(15)');

        $this->assertSame(['select orders q=1', 'select orders q=1'], $this->events($w));
        $this->assertSame(CardinalityKind::Bounded, $simple->card->kind);
        $this->assertSame(CardinalityKind::Bounded, $cursor->card->kind);
        $this->assertClean($w);
    }

    public function testLimitAndTakeBoundTheRead(): void
    {
        $w = $this->walker();
        $limited = $w->run('Order::limit(10)->get()');
        $taken = $w->run('Order::where("a", 1)->take(5)->get()');
        $builder = $w->run('Order::limit(10)');

        $this->assertSame(['select orders q=1', 'select orders q=1'], $this->events($w));
        $this->assertSame(CardinalityKind::Bounded, $limited->card->kind);
        $this->assertSame(CardinalityKind::Bounded, $taken->card->kind);
        $this->assertInstanceOf(BuilderT::class, $builder);
        $this->assertTrue($builder->bounded);
        $this->assertClean($w);
    }

    public function testFirstFindAndSoleReadOneRow(): void
    {
        $w = $this->walker();
        foreach (['Order::first()', 'Order::find(1)', 'Order::findOrFail(1)', 'Order::where("a", 1)->sole()', 'Order::firstOrFail()'] as $php) {
            $t = $w->run($php);
            $this->assertInstanceOf(ModelT::class, $t, $php);
            $this->assertSame(self::M . 'Order', $t->class);
        }

        $this->assertSame(array_fill(0, 5, 'select orders q=1'), $this->events($w));
        $this->assertClean($w);
    }

    public function testFindWithAnArrayLiteralIsBoundedAndWithAListKeepsItsSize(): void
    {
        $w = $this->walker(['ids' => $this->rows('Customer', 'customers'), 'few' => new ListT(new PlainT(), Cardinality::bounded(), Delivery::Materialized)]);
        $literal = $w->run('Order::find([1, 2, 3])');
        $fromTable = $w->run('Order::find($ids)');
        $fromBounded = $w->run('Order::findMany($few)');

        $this->assertSame([
            'select orders q=1',
            'select orders q=1 m=1:orders:materialize',
            'select orders q=1',
        ], $this->events($w));
        $this->assertSame(CardinalityKind::Bounded, $literal->card->kind);
        $this->assertSame(CardinalityKind::Table, $fromTable->card->kind);
        $this->assertSame('orders', $fromTable->card->origin?->table);
        $this->assertSame(CardinalityKind::Bounded, $fromBounded->card->kind);
        $this->assertClean($w);
    }

    public function testFindWithAnIdThatIsPlainIsOneRow(): void
    {
        $w = $this->walker(['id' => new PlainT()]);
        $t = $w->run('Order::find($id)');

        $this->assertInstanceOf(ModelT::class, $t);
        $this->assertSame(['select orders q=1'], $this->events($w));
        $this->assertClean($w);
    }

    public function testAggregatesAreOneStatementAndReturnAPlainValue(): void
    {
        $w = $this->walker();
        foreach (['Order::count()', 'Order::where("a", 1)->exists()', 'Order::sum("total")', 'Order::max("total")', 'Order::avg("total")', 'Order::doesntExist()'] as $php) {
            $this->assertInstanceOf(PlainT::class, $w->run($php), $php);
        }

        $this->assertSame(array_fill(0, 6, 'aggregate orders q=1'), $this->events($w));
        $this->assertClean($w);
    }

    public function testPluckKeepsWhereTheSizeComesFrom(): void
    {
        $w = $this->walker();
        $t = $w->run('Customer::pluck("id")');

        $this->assertSame(['select customers q=1 m=1:customers:materialize'], $this->events($w));
        $this->assertInstanceOf(ListT::class, $t);
        $this->assertInstanceOf(PlainT::class, $t->elem);
        $this->assertSame('customers', $t->card->origin?->table);
        $this->assertSame(Delivery::Materialized, $t->delivery);
        $this->assertClean($w);
    }

    public function testPluckAfterLimitIsBounded(): void
    {
        $w = $this->walker();
        $t = $w->run('Customer::limit(5)->pluck("id")');

        $this->assertSame(['select customers q=1'], $this->events($w));
        $this->assertSame(CardinalityKind::Bounded, $t->card->kind);
        $this->assertClean($w);
    }

    public function testQueryAndOnReturnABuilderWithoutAnEvent(): void
    {
        $w = $this->walker();
        $t = $w->run('Order::query()');

        $this->assertInstanceOf(BuilderT::class, $t);
        $this->assertSame(self::M . 'Order', $t->class);
        $this->assertSame('orders', $t->table);
        $this->assertSame([], $this->events($w));
        $this->assertClean($w);
    }

    public function testWhenRunsTheClosureOnceAndAdoptsTheWorseOfTheTwoBuilders(): void
    {
        $w = $this->walker();
        $bounded = $w->run('Order::when(true, fn ($q) => $q->limit(5))->get()');
        $this->assertSame(['select orders q=1 m=1:orders:materialize'], $this->events($w));
        // The callback may not run, so the limit it adds cannot be counted on; the assumption says so.
        $this->assertSame(CardinalityKind::Table, $bounded->card->kind);
        $this->assertSame(['BRANCH_WORST_CASE'], $this->assumptionCodes($w));

        $w = $this->walker();
        $plain = $w->run('Order::when(true, fn ($q) => $q->where("a", 1))->get()');
        $unless = $w->run('Order::unless(true, fn ($q) => $q->orderBy("id"))->get()');
        $this->assertSame(CardinalityKind::Table, $plain->card->kind);
        $this->assertSame(CardinalityKind::Table, $unless->card->kind);
        $this->assertSame([], $this->assumptionCodes($w));
        $this->assertClean($w);
    }

    public function testWhenRunsTheClosureBodySoItsQueriesAreSeen(): void
    {
        $w = $this->walker();
        $w->run('Order::when(true, fn ($q) => $q->whereIn("id", Customer::pluck("id")))->limit(3)->get()');

        $this->assertSame(['select customers q=1 m=1:customers:materialize', 'select orders q=1'], $this->events($w));
        $this->assertClean($w);
    }

    public function testWhenWithACallbackThatIsNotAClosureIsAGap(): void
    {
        $w = $this->walker(['callback' => new PlainT()]);
        $w->run('Order::when(true, $callback)->get()');

        $this->assertSame(['UNRESOLVED_CALL'], $this->gapCodes($w));
        $this->assertClean($w);
    }

    public function testNestedQueriesInConstraintClosuresAreNotLost(): void
    {
        $w = $this->walker();
        $w->run('Order::whereHas("items", fn ($q) => $q->whereIn("id", Customer::pluck("id")))->first()');

        $this->assertContains('select customers q=1 m=1:customers:materialize', $this->events($w));
        $this->assertContains('select orders q=1', $this->events($w));
        $this->assertClean($w);
    }

    public function testNewModelAndMakeCostNothing(): void
    {
        $w = $this->walker();
        $new = $w->run('new Order(["a" => 1])');
        $made = $w->run('Order::make(["a" => 1])');

        $this->assertInstanceOf(ModelT::class, $new);
        $this->assertInstanceOf(ModelT::class, $made);
        $this->assertSame([], $this->events($w));
        $this->assertClean($w);
    }

    public function testAModelWithNoExportedInfoIsAGapNotAGuess(): void
    {
        $w = $this->walker();
        $t = $w->run('NoInfo::get()');

        $this->assertInstanceOf(UnknownT::class, $t);
        $this->assertSame(['MODEL_INFO_UNAVAILABLE'], $this->gapCodes($w));
        $this->assertSame([], $this->events($w));
        $this->assertClean($w);
    }
}
