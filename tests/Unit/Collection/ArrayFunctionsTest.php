<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Collection;

use PeakSim\Analysis\Handlers\Collection\ArrayFunctions;
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
use PeakSim\Tests\Support\FakeEvalContext;
use PeakSim\Tests\Unit\Collection\Support\Calls;
use PeakSim\Tests\Unit\Collection\Support\Probe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** PHP array functions: callbacks are loops, the size of the array is carried through (design 1.9 CollectionOps, c-array-map, c-usort). */
final class ArrayFunctionsTest extends TestCase
{
    private function orders(): ListT
    {
        return new ListT(new ModelT('App\Models\Order'), Cardinality::table('orders'));
    }

    public function testItTakesTheArrayFunctionsItKnowsAndNothingElse(): void
    {
        $ctx = new FakeEvalContext();
        $handler = new ArrayFunctions();

        foreach (['collect', 'array_map', 'array_filter', 'array_reduce', 'array_walk', 'usort', 'uasort', 'uksort', 'array_chunk', 'array_slice', 'array_values', 'array_merge', 'array_keys', 'array_unique', 'array_reverse', 'array_column', 'iterator_to_array', 'range'] as $name) {
            self::assertTrue($handler->handles(Calls::func($name . '($a)', [0 => $this->orders()]), $ctx), $name);
        }
        foreach (['count', 'in_array', 'array_sum', 'implode', 'response', 'strtoupper'] as $name) {
            self::assertFalse($handler->handles(Calls::func($name . '($a)', [0 => $this->orders()]), $ctx), $name . ' is for the known-calls handler or nobody');
        }
    }

    public function testArrayMapRunsItsCallbackOncePerElementInOneLoop(): void
    {
        $probe = new Probe(new PlainT());

        $result = $probe->run(new ArrayFunctions(), Calls::func('array_map(fn ($o) => $o->customer->name, $orders)', [1 => $this->orders()]));

        self::assertCount(1, $probe->invocations);
        self::assertCount(1, $probe->invocations[0]['frames']);
        self::assertSame('orders', $probe->invocations[0]['frames'][0]->origin->table);
        self::assertInstanceOf(ModelT::class, $probe->invocations[0]['args'][0]);
        self::assertInstanceOf(ListT::class, $result);
        self::assertSame('orders', $result->card->origin?->table);
        self::assertSame([], $probe->ctx->violations());
    }

    public function testArrayMapWithTwoArraysLoopsOverTheFirstAndPassesBothElements(): void
    {
        $probe = new Probe();
        $other = new ListT(new PlainT(), Cardinality::bounded());

        $probe->run(new ArrayFunctions(), Calls::func('array_map(fn ($o, $n) => 1, $orders, $names)', [1 => $this->orders(), 2 => $other]));

        self::assertCount(2, $probe->invocations[0]['args']);
        self::assertCount(1, $probe->invocations[0]['frames']);
    }

    public function testArrayFilterWithACallbackIsALoopAndWithoutOneIsNot(): void
    {
        $with = new Probe();
        $result = $with->run(new ArrayFunctions(), Calls::func('array_filter($orders, fn ($o) => $o->customer->id > 1)', [0 => $this->orders()]));
        self::assertCount(1, $with->invocations[0]['frames']);
        self::assertSame($this->orders()->fingerprint(), $result->fingerprint());

        $without = new Probe();
        $without->run(new ArrayFunctions(), Calls::func('array_filter($orders)', [0 => $this->orders()]));
        self::assertSame([], $without->invocations);
    }

    public function testArrayReduceGetsTheRunningValueAndTheElement(): void
    {
        $probe = new Probe();

        $probe->run(new ArrayFunctions(), Calls::func('array_reduce($orders, fn ($carry, $o) => $carry + $o->customer->id, 0)', [0 => $this->orders()]));

        self::assertCount(1, $probe->invocations[0]['frames']);
        self::assertCount(2, $probe->invocations[0]['args']);
        self::assertInstanceOf(ModelT::class, $probe->invocations[0]['args'][1]);
    }

    public function testArrayWalkRunsOncePerElement(): void
    {
        $probe = new Probe();

        $result = $probe->run(new ArrayFunctions(), Calls::func('array_walk($orders, fn ($o) => $o->customer)', [0 => $this->orders()]));

        self::assertCount(1, $probe->invocations[0]['frames']);
        self::assertInstanceOf(PlainT::class, $result);
    }

    /** @return iterable<string, array{string}> */
    public static function sorters(): iterable
    {
        yield 'usort' => ['usort'];
        yield 'uasort' => ['uasort'];
        yield 'uksort' => ['uksort'];
    }

    #[DataProvider('sorters')]
    public function testTheComparatorOfASortRunsInsideTheLoopWithTwoElements(string $name): void
    {
        $probe = new Probe();

        $result = $probe->run(new ArrayFunctions(), Calls::func($name . '($orders, fn ($a, $b) => $a->customer->id <=> $b->customer->id)', [0 => $this->orders()]));

        self::assertCount(1, $probe->invocations);
        self::assertCount(1, $probe->invocations[0]['frames']);
        self::assertCount(2, $probe->invocations[0]['args']);
        self::assertInstanceOf(ModelT::class, $probe->invocations[0]['args'][0]);
        self::assertInstanceOf(ModelT::class, $probe->invocations[0]['args'][1]);
        self::assertInstanceOf(PlainT::class, $result);
    }

    public function testArrayChunkKeepsTheOuterSizeSoAnInsertPerChunkStaysLinear(): void
    {
        $result = (new Probe())->run(new ArrayFunctions(), Calls::func('array_chunk($rows, 1000)', [0 => new ListT(new PlainT(), Cardinality::table('customers'))]));

        self::assertInstanceOf(ListT::class, $result);
        self::assertSame('customers', $result->card->origin?->table);
        self::assertInstanceOf(ListT::class, $result->elem);
        self::assertSame(CardinalityKind::Bounded, $result->elem->card->kind);
    }

    public function testArraySliceWithALengthIsBounded(): void
    {
        $bounded = (new Probe())->run(new ArrayFunctions(), Calls::func('array_slice($orders, 0, 10)', [0 => $this->orders()]));
        $open = (new Probe())->run(new ArrayFunctions(), Calls::func('array_slice($orders, 1)', [0 => $this->orders()]));

        self::assertInstanceOf(ListT::class, $bounded);
        self::assertInstanceOf(ListT::class, $open);
        self::assertSame(CardinalityKind::Bounded, $bounded->card->kind);
        self::assertSame(CardinalityKind::Table, $open->card->kind);
    }

    public function testValuesReverseAndUniqueKeepTheList(): void
    {
        foreach (['array_values', 'array_reverse', 'array_unique'] as $name) {
            $result = (new Probe())->run(new ArrayFunctions(), Calls::func($name . '($orders)', [0 => $this->orders()]));
            self::assertSame($this->orders()->fingerprint(), $result->fingerprint(), $name);
        }
    }

    public function testKeysAndColumnGivePlainValuesAsLongAsTheArray(): void
    {
        foreach (['array_keys($orders)', "array_column(\$orders, 'id')"] as $call) {
            $result = (new Probe())->run(new ArrayFunctions(), Calls::func($call, [0 => $this->orders()]));
            self::assertInstanceOf(ListT::class, $result, $call);
            self::assertInstanceOf(PlainT::class, $result->elem, $call);
            self::assertSame('orders', $result->card->origin?->table, $call);
        }
    }

    public function testMergeIsAsLongAsTheLongestAndUnknownIfAnyPartIsOfUnknownSize(): void
    {
        $short = new ListT(new PlainT(), Cardinality::bounded());

        $table = (new Probe())->run(new ArrayFunctions(), Calls::func('array_merge($a, $b)', [0 => $short, 1 => $this->orders()]));
        self::assertInstanceOf(ListT::class, $table);
        self::assertSame('orders', $table->card->origin?->table);

        $unknown = (new Probe())->run(new ArrayFunctions(), Calls::func('array_merge($a, $b)', [0 => $short, 1 => new PlainT()]));
        self::assertInstanceOf(ListT::class, $unknown);
        self::assertSame(CardinalityKind::Unknown, $unknown->card->kind, 'a value of unknown shape may be any length');

        $literal = (new Probe())->run(new ArrayFunctions(), Calls::func('array_merge($a, [1, 2])', [0 => $short]));
        self::assertInstanceOf(ListT::class, $literal);
        self::assertSame(CardinalityKind::Bounded, $literal->card->kind);
    }

    public function testCollectKeepsAListAndMakesAListOutOfAnythingElse(): void
    {
        $same = (new Probe())->run(new ArrayFunctions(), Calls::func('collect($orders)', [0 => $this->orders()]));
        self::assertSame($this->orders()->fingerprint(), $same->fingerprint());

        $empty = (new Probe())->run(new ArrayFunctions(), Calls::func('collect()'));
        self::assertInstanceOf(ListT::class, $empty);
        self::assertSame(CardinalityKind::Bounded, $empty->card->kind);

        $shape = (new Probe())->run(new ArrayFunctions(), Calls::func('collect($row)', [0 => new ShapeT(['a' => new PlainT()])]));
        self::assertInstanceOf(ListT::class, $shape);
        self::assertSame(CardinalityKind::Bounded, $shape->card->kind);

        $plain = (new Probe())->run(new ArrayFunctions(), Calls::func('collect($input)', [0 => new PlainT()]));
        self::assertInstanceOf(ListT::class, $plain);
        self::assertSame(CardinalityKind::Unknown, $plain->card->kind);
    }

    public function testCollectOfAnUnknownStaysUnknown(): void
    {
        $probe = new Probe();
        $unknown = $probe->ctx->gap(GapCode::UNRESOLVED_CALL, Calls::parse('foo();'), 'made elsewhere');

        $result = $probe->run(new ArrayFunctions(), Calls::func('collect($x)', [0 => $unknown]));

        self::assertSame($unknown, $result);
        self::assertSame([], $probe->ctx->violations());
    }

    public function testIteratorToArrayHoldsAStream(): void
    {
        $stream = new ListT(new ModelT('App\Models\Order'), Cardinality::table('orders'), Delivery::Streamed);
        $probe = new Probe();

        $result = $probe->run(new ArrayFunctions(), Calls::func('iterator_to_array($stream)', [0 => $stream]));

        self::assertCount(1, $probe->fake->events());
        self::assertSame(EventKind::Payload, $probe->fake->events()[0]->kind);
        self::assertInstanceOf(ListT::class, $result);
        self::assertSame(Delivery::Materialized, $result->delivery);
    }

    public function testRangeOfLiteralsIsBoundedAndOtherwiseOfUnknownSize(): void
    {
        $fixed = (new Probe())->run(new ArrayFunctions(), Calls::func('range(1, 5)'));
        $open = (new Probe())->run(new ArrayFunctions(), Calls::func('range(1, $n)', [1 => new PlainT()]));

        self::assertInstanceOf(ListT::class, $fixed);
        self::assertInstanceOf(ListT::class, $open);
        self::assertSame(CardinalityKind::Bounded, $fixed->card->kind);
        self::assertSame(CardinalityKind::Unknown, $open->card->kind);
    }

    public function testALiteralFunctionNameAsCallbackRunsNothing(): void
    {
        $probe = new Probe();

        $result = $probe->run(new ArrayFunctions(), Calls::func("array_map('trim', \$names)", [1 => new ListT(new PlainT(), Cardinality::table('customers'))]));

        self::assertSame([], $probe->invocations);
        self::assertSame([], $probe->fake->gaps());
        self::assertInstanceOf(ListT::class, $result);
    }

    public function testACallbackThatIsAnArrayCallableOrAnObjectIsAGap(): void
    {
        $probe = new Probe();

        $probe->run(new ArrayFunctions(), Calls::func('array_map($callable, $orders)', [0 => new ObjectT('App\Invokable'), 1 => $this->orders()]));

        self::assertSame([GapCode::UNRESOLVED_CALL], array_map(static fn ($g) => $g->code, $probe->fake->gaps()));
    }

    public function testAnArrayOfUnknownSizeWithWorkInTheCallbackIsAGap(): void
    {
        $probe = new Probe();

        $probe->run(new ArrayFunctions(), Calls::func('array_map(fn ($x) => lookup($x), $ids)', [1 => new ListT(new PlainT(), Cardinality::unknown())]));

        self::assertSame([GapCode::LOOP_SOURCE_UNKNOWN], array_map(static fn ($g) => $g->code, $probe->fake->gaps()));
    }

    public function testAnArgumentThatIsNotAnArrayAtAllIsAGapAndKeepsTheResultUnknown(): void
    {
        $probe = new Probe();

        $result = $probe->run(new ArrayFunctions(), Calls::func('array_map(fn ($x) => $x, $thing)', [1 => new ObjectT('App\Foo')]));

        self::assertInstanceOf(UnknownT::class, $result);
        self::assertSame([GapCode::UNRESOLVED_CALL], array_map(static fn ($g) => $g->code, $probe->fake->gaps()));
        self::assertSame([], $probe->ctx->violations());
    }
}
