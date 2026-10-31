<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Collection;

use PeakSim\Analysis\Contracts\HandlerRegistry;
use PeakSim\Analysis\Handlers\Collection\CollectionOps;
use PeakSim\Analysis\Type\BuilderT;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\ObjectT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\ShapeT;
use PeakSim\Analysis\Type\UnknownT;
use PeakSim\Model\Cardinality;
use PeakSim\Model\CardinalityKind;
use PeakSim\Model\Delivery;
use PeakSim\Model\EventKind;
use PeakSim\Model\GapCode;
use PeakSim\Model\MemoryCause;
use PeakSim\Tests\Support\FakeEvalContext;
use PeakSim\Tests\Unit\Collection\Support\Calls;
use PeakSim\Tests\Unit\Collection\Support\Probe;
use PeakSim\Tests\Unit\Engine\Support\FakeModelCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** CollectionOps on its own, with a recording fake context (design 1.9 CollectionOps, 1.5, 1.6). */
final class ListOpsTest extends TestCase
{
    private const ORDER = 'App\Models\Order';

    private function orders(Delivery $delivery = Delivery::Materialized, bool $window = false): ListT
    {
        return new ListT(new ModelT(self::ORDER), Cardinality::table('orders'), $delivery, $window);
    }

    private function ops(): CollectionOps
    {
        return new CollectionOps();
    }

    private function models(): FakeEvalContext
    {
        return new FakeEvalContext(models: new FakeModelCatalog([
            self::ORDER => ['table' => 'orders', 'relations' => ['customer' => ['App\Models\Customer', false], 'items' => ['App\Models\Item', true]]],
            'App\Models\Customer' => ['table' => 'customers'],
            'App\Models\Item' => ['table' => 'items', 'relations' => ['product' => ['App\Models\Product', false]]],
            'App\Models\Product' => ['table' => 'products'],
        ]), closureInvoker: static fn () => new PlainT());
    }

    // ----- what it takes -------------------------------------------------------------------------------------

    public function testItTakesMethodsOnAListAndNotOnABuilderOrAModel(): void
    {
        $ctx = new FakeEvalContext();

        self::assertTrue($this->ops()->handles(Calls::method($this->orders(), 'map(fn ($o) => $o)'), $ctx));
        self::assertFalse($this->ops()->handles(Calls::method(new BuilderT(self::ORDER), "where('a', 1)"), $ctx), 'the builder where() is the query handler\'s');
        self::assertFalse($this->ops()->handles(Calls::method(new ModelT(self::ORDER), 'map(fn ($o) => $o)'), $ctx));
        self::assertFalse($this->ops()->handles(Calls::method(new PlainT(), 'map(fn ($o) => $o)'), $ctx));
    }

    // ----- loops ---------------------------------------------------------------------------------------------

    /** @return iterable<string, array{string}> */
    public static function loopingMethods(): iterable
    {
        foreach (['each', 'map', 'filter', 'reject', 'flatMap', 'mapWithKeys', 'partition', 'takeWhile', 'skipWhile', 'unique', 'keyBy', 'groupBy', 'sortBy', 'sortByDesc', 'contains', 'every', 'some', 'first', 'last', 'sum', 'avg', 'max', 'min'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('loopingMethods')]
    public function testACallbackRunsOnceInsideOneLoopOverTheTable(string $method): void
    {
        $probe = new Probe();

        $probe->run($this->ops(), Calls::method($this->orders(), $method . '(fn ($o) => $o->total)'));

        self::assertCount(1, $probe->invocations);
        self::assertCount(1, $probe->invocations[0]['frames']);
        self::assertSame('orders', $probe->invocations[0]['frames'][0]->origin->table);
        self::assertFalse($probe->invocations[0]['frames'][0]->streamed);
        self::assertInstanceOf(ModelT::class, $probe->invocations[0]['args'][0]);
        self::assertSame([], $probe->fake->frames(), 'the loop is closed again afterwards');
        self::assertSame([], $probe->ctx->violations());
    }

    public function testTheElementKeepsTheTableItCameFrom(): void
    {
        $probe = new Probe();

        $probe->run($this->ops(), Calls::method($this->orders(), 'each(fn ($o) => 1)'));

        $elem = $probe->invocations[0]['args'][0];
        self::assertInstanceOf(ModelT::class, $elem);
        self::assertSame('orders', $elem->origin?->table);
    }

    public function testTheLoopOverAStreamIsStreamed(): void
    {
        $probe = new Probe();

        $probe->run($this->ops(), Calls::method($this->orders(Delivery::Streamed), 'each(fn ($o) => 1)'));

        self::assertTrue($probe->invocations[0]['frames'][0]->streamed);
    }

    public function testTheWindowOfAChunkIsNotLoopedAgain(): void
    {
        $probe = new Probe();

        $probe->run($this->ops(), Calls::method($this->orders(Delivery::Streamed, true), 'each(fn ($o) => 1)'));

        self::assertCount(1, $probe->invocations);
        self::assertSame([], $probe->invocations[0]['frames'], 'N/c windows of c rows is N, already counted by the chunk loop');
    }

    public function testABoundedListMakesNoLoopAndNoGap(): void
    {
        $probe = new Probe();
        $list = new ListT(new ModelT(self::ORDER), Cardinality::bounded());

        $probe->run($this->ops(), Calls::method($list, 'each(fn ($o) => $o->customer)'));

        self::assertSame([], $probe->invocations[0]['frames']);
        self::assertSame([], $probe->fake->gaps());
    }

    public function testAListOfUnknownSizeWithACallbackThatDoesSomethingIsAGap(): void
    {
        $probe = new Probe();
        $list = new ListT(new PlainT(), Cardinality::unknown());

        $probe->run($this->ops(), Calls::method($list, 'map(fn ($x) => lookup($x))'));

        self::assertSame([GapCode::LOOP_SOURCE_UNKNOWN], array_map(static fn ($g) => $g->code, $probe->fake->gaps()));
        self::assertSame([], $probe->invocations[0]['frames'], 'no loop is invented for a size that is not known');
    }

    public function testAListOfUnknownSizeWithACallbackThatDoesNothingIsFine(): void
    {
        $probe = new Probe();
        $list = new ListT(new PlainT(), Cardinality::unknown());

        $probe->run($this->ops(), Calls::method($list, 'map(fn ($x) => $x + 1)'));

        self::assertSame([], $probe->fake->gaps());
    }

    public function testACallbackThatIsNotAClosureIsAGapButALiteralFunctionNameIsNot(): void
    {
        $probe = new Probe();

        $probe->run($this->ops(), Calls::method($this->orders(), 'map($callable)', [0 => new ObjectT('App\Invokable')]));
        self::assertSame([GapCode::UNRESOLVED_CALL], array_map(static fn ($g) => $g->code, $probe->fake->gaps()));

        $other = new Probe();
        $other->run($this->ops(), Calls::method($this->orders(), "map('strtoupper')"));
        self::assertSame([], $other->fake->gaps());
        self::assertSame([], $other->invocations, 'a builtin function name runs nothing of the app');
    }

    public function testASortKeyIsNotACallback(): void
    {
        $probe = new Probe();

        $result = $probe->run($this->ops(), Calls::method($this->orders(), "sortBy('total')"));

        self::assertSame([], $probe->invocations);
        self::assertInstanceOf(ListT::class, $result);
        self::assertSame('orders', $result->card->origin?->table);
    }

    // ----- what comes back -----------------------------------------------------------------------------------

    public function testMapGivesAListOfWhatTheCallbackReturnedAsLongAsTheOriginalList(): void
    {
        $probe = new Probe(new ModelT('App\Models\Customer'));

        $result = $probe->run($this->ops(), Calls::method($this->orders(), 'map(fn ($o) => $o->customer)'));

        self::assertInstanceOf(ListT::class, $result);
        self::assertInstanceOf(ModelT::class, $result->elem);
        self::assertSame('App\Models\Customer', $result->elem->class);
        self::assertSame('orders', $result->card->origin?->table, 'one customer per order: as long as orders');
    }

    public function testEachGivesTheListBack(): void
    {
        $list = $this->orders();

        $result = (new Probe())->run($this->ops(), Calls::method($list, 'each(fn ($o) => 1)'));

        self::assertSame($list->fingerprint(), $result->fingerprint());
    }

    public function testFilterAndRejectKeepTheListAsItIs(): void
    {
        $list = $this->orders();

        foreach (['filter(fn ($o) => $o->x)', 'reject(fn ($o) => $o->x)', 'filter()', 'values()', 'all()', 'toArray()', 'reverse()', 'sort()', 'where(\'a\', 1)', 'whereIn(\'a\', [1])', 'unique()'] as $call) {
            $result = (new Probe())->run($this->ops(), Calls::method($list, $call));
            self::assertSame($list->fingerprint(), $result->fingerprint(), $call);
        }
    }

    public function testFlatMapOfBoundedListsKeepsTheOuterSize(): void
    {
        $probe = new Probe(new ListT(new PlainT(), Cardinality::bounded()));

        $result = $probe->run($this->ops(), Calls::method($this->orders(), 'flatMap(fn ($o) => [1, 2])'));

        self::assertInstanceOf(ListT::class, $result);
        self::assertSame(CardinalityKind::Table, $result->card->kind);
        self::assertSame('orders', $result->card->origin?->table);
    }

    public function testFlatMapOfAListThatGrowsWithATableInsideAListThatDoesIsOfUnknownSize(): void
    {
        $probe = new Probe(new ListT(new PlainT(), Cardinality::table('items', 'orders')));

        $result = $probe->run($this->ops(), Calls::method($this->orders(), "flatMap(fn (\$o) => \$o->items->pluck('id'))"));

        self::assertInstanceOf(ListT::class, $result);
        self::assertSame(CardinalityKind::Unknown, $result->card->kind, 'N x M elements cannot be one table-sized loop: a loop over it with work in it will be a gap, not an undercount');
    }

    public function testReduceGivesTheCallbackTheRunningValueAndTheElement(): void
    {
        $probe = new Probe();

        $probe->run($this->ops(), Calls::method($this->orders(), 'reduce(fn ($carry, $o) => $carry + 1, 0)'));

        self::assertCount(1, $probe->invocations);
        self::assertInstanceOf(PlainT::class, $probe->invocations[0]['args'][0]);
        self::assertInstanceOf(ModelT::class, $probe->invocations[0]['args'][1]);
        self::assertCount(1, $probe->invocations[0]['frames']);
    }

    public function testGroupingMakesAListOfListsAsLongAsTheOriginal(): void
    {
        $result = (new Probe())->run($this->ops(), Calls::method($this->orders(), 'groupBy(fn ($o) => $o->status)'));

        self::assertInstanceOf(ListT::class, $result);
        self::assertInstanceOf(ListT::class, $result->elem);
        self::assertSame('orders', $result->card->origin?->table);
        self::assertSame('orders', $result->elem->card->origin?->table);
    }

    public function testPartitionIsAlwaysTwoLists(): void
    {
        $result = (new Probe())->run($this->ops(), Calls::method($this->orders(), 'partition(fn ($o) => $o->paid)'));

        self::assertInstanceOf(ListT::class, $result);
        self::assertSame(CardinalityKind::Bounded, $result->card->kind);
        self::assertInstanceOf(ListT::class, $result->elem);
        self::assertSame('orders', $result->elem->card->origin?->table);
    }

    public function testFirstAndLastGiveTheElementWithOrWithoutACallback(): void
    {
        foreach (['first()', 'last()', 'first(fn ($o) => $o->paid)', 'get(0)', 'sole()', 'firstOrFail()'] as $call) {
            $result = (new Probe())->run($this->ops(), Calls::method($this->orders(), $call));
            self::assertInstanceOf(ModelT::class, $result, $call);
            self::assertSame(self::ORDER, $result->class, $call);
        }
        $none = new Probe();
        $none->run($this->ops(), Calls::method($this->orders(), 'first()'));
        self::assertSame([], $none->invocations, 'without a callback nothing runs');
    }

    public function testPluckKeepsHowLongTheListIsAndWhichTableItComesFrom(): void
    {
        $list = new ListT(new ModelT('App\Models\Item'), Cardinality::table('items', 'orders'), Delivery::Streamed);

        $result = (new Probe())->run($this->ops(), Calls::method($list, "pluck('id')"));

        self::assertInstanceOf(ListT::class, $result);
        self::assertInstanceOf(PlainT::class, $result->elem);
        self::assertSame('items', $result->card->origin?->table);
        self::assertSame('orders', $result->card->origin?->perTable);
        self::assertSame(Delivery::Streamed, $result->delivery);
    }

    public function testTakeAndFriendsBoundTheList(): void
    {
        foreach (['take(5)', 'slice(1, 2)', 'forPage(1, 10)', 'random(3)'] as $call) {
            $result = (new Probe())->run($this->ops(), Calls::method($this->orders(), $call));
            self::assertInstanceOf(ListT::class, $result, $call);
            self::assertSame(CardinalityKind::Bounded, $result->card->kind, $call);
        }
        foreach (['slice(1)', 'skip(2)'] as $call) {
            $result = (new Probe())->run($this->ops(), Calls::method($this->orders(), $call));
            self::assertInstanceOf(ListT::class, $result, $call);
            self::assertSame(CardinalityKind::Table, $result->card->kind, $call);
        }
    }

    public function testChunkOnACollectionOnlySplits(): void
    {
        $result = (new Probe())->run($this->ops(), Calls::method($this->orders(), 'chunk(100)'));

        self::assertInstanceOf(ListT::class, $result);
        self::assertSame('orders', $result->card->origin?->table, 'N/size chunks are still a table-sized list');
        self::assertInstanceOf(ListT::class, $result->elem);
        self::assertSame(CardinalityKind::Bounded, $result->elem->card->kind);
    }

    public function testMergeTakesTheLongerOfTheTwo(): void
    {
        $other = new ListT(new ModelT('App\Models\Customer'), Cardinality::bounded());

        $result = (new Probe())->run($this->ops(), Calls::method($other, 'merge($orders)', [0 => $this->orders()]));

        self::assertInstanceOf(ListT::class, $result);
        self::assertSame(CardinalityKind::Table, $result->card->kind);
        self::assertSame('orders', $result->card->origin?->table);
    }

    public function testFlattenOfListsThatGrowWithATableInsideATableIsOfUnknownSize(): void
    {
        $nested = new ListT(new ListT(new PlainT(), Cardinality::table('items', 'orders')), Cardinality::table('orders'));

        $result = (new Probe())->run($this->ops(), Calls::method($nested, 'flatten()'));
        self::assertInstanceOf(ListT::class, $result);
        self::assertSame(CardinalityKind::Unknown, $result->card->kind);

        $flat = new ListT(new PlainT(), Cardinality::table('orders'));
        self::assertSame($flat->fingerprint(), (new Probe())->run($this->ops(), Calls::method($flat, 'flatten()'))->fingerprint());
    }

    public function testScalarsCostNothing(): void
    {
        foreach (['count()', 'isEmpty()', 'isNotEmpty()', "implode(',')", 'sum()', 'toJson()', 'contains(1)'] as $call) {
            $probe = new Probe();
            $result = $probe->run($this->ops(), Calls::method($this->orders(), $call));
            self::assertInstanceOf(PlainT::class, $result, $call);
            self::assertSame([], $probe->fake->events(), $call);
        }
    }

    public function testTapPipeAndWhenRunTheirCallbackOnceWithoutALoop(): void
    {
        $tap = new Probe();
        $tap->run($this->ops(), Calls::method($this->orders(), 'tap(fn ($list) => 1)'));
        self::assertCount(1, $tap->invocations);
        self::assertSame([], $tap->invocations[0]['frames']);
        self::assertInstanceOf(ListT::class, $tap->invocations[0]['args'][0]);

        $pipe = new Probe(new ModelT('App\Models\Customer'));
        $result = $pipe->run($this->ops(), Calls::method($this->orders(), 'pipe(fn ($list) => $list->first()->customer)'));
        self::assertInstanceOf(ModelT::class, $result);
        self::assertSame('App\Models\Customer', $result->class);
    }

    // ----- streams -------------------------------------------------------------------------------------------

    /** @return iterable<string, array{string}> */
    public static function materializing(): iterable
    {
        foreach (['all()', 'toArray()', 'collect()', 'sort()', "sortBy('total')", "groupBy('status')", 'unique()', 'reverse()'] as $call) {
            yield $call => [$call];
        }
    }

    #[DataProvider('materializing')]
    public function testAStreamHeldAtOnceIsMemoryProportionalToTheTable(string $call): void
    {
        $probe = new Probe();

        $result = $probe->run($this->ops(), Calls::method($this->orders(Delivery::Streamed), $call));

        self::assertCount(1, $probe->fake->events());
        $event = $probe->fake->events()[0];
        self::assertSame(EventKind::Payload, $event->kind);
        self::assertNull($event->query);
        self::assertSame(MemoryCause::Materialize, $event->memory?->cause);
        self::assertSame(1, $event->memory?->ownDegree);
        self::assertSame('orders', $event->memory?->ownOrigin?->table);
        self::assertSame('orders', $event->table);
        self::assertInstanceOf(ListT::class, $result);
        self::assertSame(Delivery::Materialized, $result->delivery);
    }

    public function testAListThatIsAlreadyHeldOrAWindowIsNotMaterialisedAgain(): void
    {
        foreach ([$this->orders(Delivery::Materialized), $this->orders(Delivery::Streamed, true)] as $list) {
            $probe = new Probe();
            $probe->run($this->ops(), Calls::method($list, 'all()'));
            self::assertSame([], $probe->fake->events());
        }
    }

    public function testMapOnAStreamStaysAStream(): void
    {
        $result = (new Probe())->run($this->ops(), Calls::method($this->orders(Delivery::Streamed), 'map(fn ($o) => 1)'));

        self::assertInstanceOf(ListT::class, $result);
        self::assertSame(Delivery::Streamed, $result->delivery);
    }

    // ----- loading relations ---------------------------------------------------------------------------------

    public function testLoadReadsTheRelationInOneQueryAndMarksTheElementsLoaded(): void
    {
        $probe = new Probe(null, $this->models());

        $result = $probe->run($this->ops(), Calls::method($this->orders(), "load('customer')"));

        self::assertCount(1, $probe->fake->events());
        self::assertSame(EventKind::EagerLoad, $probe->fake->events()[0]->kind);
        self::assertSame('customers', $probe->fake->events()[0]->table);
        self::assertNotNull($probe->fake->events()[0]->query);
        self::assertInstanceOf(ListT::class, $result);
        self::assertInstanceOf(ModelT::class, $result->elem);
        self::assertSame(['customer'], $result->elem->eager);
        self::assertSame([], $probe->fake->gaps());
    }

    public function testLoadOfAToManyRelationAddsItsRowsToMemory(): void
    {
        $probe = new Probe(null, $this->models());

        $probe->run($this->ops(), Calls::method($this->orders(), "load('items')"));

        $event = $probe->fake->events()[0];
        self::assertSame(MemoryCause::EagerToMany, $event->memory?->cause);
        self::assertSame('items', $event->memory?->ownOrigin?->table);
        self::assertSame('orders', $event->memory?->ownOrigin?->perTable);
    }

    public function testLoadOfADottedPathIsOneQueryPerStep(): void
    {
        $probe = new Probe(null, $this->models());

        $result = $probe->run($this->ops(), Calls::method($this->orders(), "load('items.product')"));

        self::assertCount(2, $probe->fake->events());
        self::assertInstanceOf(ListT::class, $result);
        self::assertInstanceOf(ModelT::class, $result->elem);
        self::assertSame(['items', 'items.product'], $result->elem->eager);
    }

    public function testLoadMissingSkipsWhatIsAlreadyLoaded(): void
    {
        $loaded = new ListT(new ModelT(self::ORDER, ['customer']), Cardinality::table('orders'));
        $probe = new Probe(null, $this->models());

        $probe->run($this->ops(), Calls::method($loaded, "loadMissing(['customer', 'items'])"));

        self::assertCount(1, $probe->fake->events(), 'only items is read');
        self::assertSame('items', $probe->fake->events()[0]->table);
    }

    public function testLoadWithARelationThatIsNotLiteralIsAGap(): void
    {
        $probe = new Probe(null, $this->models());

        $probe->run($this->ops(), Calls::method($this->orders(), 'load($relation)', [0 => new PlainT()]));

        self::assertSame([GapCode::UNRESOLVED_CALL], array_map(static fn ($g) => $g->code, $probe->fake->gaps()));
    }

    public function testLoadOnAListThatIsNotOfModelsIsAGap(): void
    {
        $probe = new Probe(null, $this->models());

        $probe->run($this->ops(), Calls::method(new ListT(new PlainT(), Cardinality::table('orders')), "load('customer')"));

        self::assertSame([GapCode::UNKNOWN_COLLECTION_METHOD], array_map(static fn ($g) => $g->code, $probe->fake->gaps()));
    }

    public function testLoadCountIsOneAggregateStatement(): void
    {
        $probe = new Probe(null, $this->models());

        $probe->run($this->ops(), Calls::method($this->orders(), "loadCount('items')"));

        self::assertCount(1, $probe->fake->events());
        self::assertSame(EventKind::Aggregate, $probe->fake->events()[0]->kind);
        self::assertNull($probe->fake->events()[0]->memory);
    }

    // ----- what it does not know -----------------------------------------------------------------------------

    public function testAMethodThatIsNotACollectionMethodMayBeAMacroAndIsAGap(): void
    {
        $probe = new Probe();

        $result = $probe->run($this->ops(), Calls::method($this->orders(), 'customMacro()'));

        self::assertInstanceOf(UnknownT::class, $result);
        self::assertSame([GapCode::UNKNOWN_COLLECTION_METHOD], array_map(static fn ($g) => $g->code, $probe->fake->gaps()));
        self::assertStringContainsString('customMacro', $probe->fake->gaps()[0]->message);
        self::assertSame([], $probe->ctx->violations(), 'the Unknown came from a gap');
    }

    public function testMethodNamesAreNotCaseSensitive(): void
    {
        $result = (new Probe())->run($this->ops(), Calls::method($this->orders(), 'Values()'));

        self::assertInstanceOf(ListT::class, $result);
    }

    public function testTheMethodNameOfAShapeIsNotMistakenForAList(): void
    {
        $ctx = new FakeEvalContext();

        self::assertFalse($this->ops()->handles(Calls::method(new ShapeT(['a' => new PlainT()]), 'map(fn ($x) => $x)'), $ctx));
    }
}
