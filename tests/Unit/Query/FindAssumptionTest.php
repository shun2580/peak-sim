<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Query;

use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Model\Axis;
use PeakSim\Model\Cardinality;
use PeakSim\Model\Delivery;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * find($x) with an argument that is only "a plain value": it may be one id or an array of ids (?id[]=1&id[]=2),
 * and a plain value cannot tell which. The analysis takes the common case (one row) and says so with the
 * assumption FIND_ARGUMENT_ASSUMED_SCALAR, on both axes. Nothing is assumed when the argument settles it.
 */
final class FindAssumptionTest extends QueryCase
{
    private const CODE = 'FIND_ARGUMENT_ASSUMED_SCALAR';

    /** @return array<string, array{0: string}> */
    public static function guessedCalls(): array
    {
        return [
            'find' => ['Order::find($id)'],
            'findOrFail' => ['Order::findOrFail($id)'],
            'findOr' => ['Order::findOr($id, fn () => 1)'],
            'find on a builder' => ['Order::where("status", "new")->find($id)'],
            'find on a relation builder' => ['$order->items()->find($id)'],
        ];
    }

    #[DataProvider('guessedCalls')]
    public function testAPlainArgumentIsTakenForOneRowAndTheAssumptionIsRecorded(string $php): void
    {
        $w = $this->walker(['order' => new ModelT(self::M . 'Order', [], null)]);

        $w->run($php);

        $this->assertSame([self::CODE], $this->assumptionCodes($w), $php);
        $this->assertClean($w);
    }

    public function testTheAssumptionHitsBothAxesAndSaysWhatWasTaken(): void
    {
        $w = $this->walker();

        $w->run('Order::find($id)');

        $this->assertCount(1, $w->fake->assumptions());
        $assumption = $w->fake->assumptions()[0];
        $this->assertSame([Axis::Query, Axis::Memory], $assumption->affects);
        $this->assertStringContainsString('find', $assumption->message);
        $this->assertStringContainsString('array', $assumption->message, 'the message must say what the argument might be');
    }

    public function testTheVerdictItselfIsUnchangedByTheAssumption(): void
    {
        $w = $this->walker(['id' => new PlainT()]);

        $t = $w->run('Order::find($id)');

        $this->assertInstanceOf(ModelT::class, $t, 'still one row');
        $this->assertSame(self::M . 'Order', $t->class);
        $this->assertSame(['select orders q=1'], $this->events($w), 'still one statement, still no memory part');
    }

    public function testEveryGuessedCallRecordsItsOwnAssumption(): void
    {
        $w = $this->walker();

        $w->run('Order::find($a)');
        $w->run('Customer::find($b)');

        $this->assertSame([self::CODE, self::CODE], $this->assumptionCodes($w), 'one per call, not one per route');
    }

    /** @return array<string, array{0: string}> */
    public static function settledCalls(): array
    {
        return [
            'an array literal' => ['Order::find([1, 2, 3])'],
            'an integer literal' => ['Order::find(1)'],
            'findOrFail with an integer literal' => ['Order::findOrFail(7)'],
            'a string literal' => ['Order::find("abc")'],
            'a model' => ['Order::find($someOrder)'],
            'findMany with a plain value (many by its name)' => ['Order::findMany($id)'],
        ];
    }

    #[DataProvider('settledCalls')]
    public function testNothingIsAssumedWhenTheArgumentSettlesIt(string $php): void
    {
        $w = $this->walker(['someOrder' => new ModelT(self::M . 'Order', [], null)]);

        $w->run($php);

        $this->assertSame([], $this->assumptionCodes($w), $php);
        $this->assertClean($w);
    }

    public function testAListOrAShapeIsStillAListAndAssumesNothing(): void
    {
        $w = $this->walker([
            'ids' => $this->rows('Customer', 'customers'),
            'few' => new ListT(new PlainT(), Cardinality::bounded(), Delivery::Materialized),
        ]);

        $fromTable = $w->run('Order::find($ids)');
        $fromBounded = $w->run('Order::find($few)');
        $literal = $w->run('Order::find([1, 2])');

        $this->assertInstanceOf(ListT::class, $fromTable);
        $this->assertInstanceOf(ListT::class, $fromBounded);
        $this->assertInstanceOf(ListT::class, $literal);
        $this->assertSame([], $this->assumptionCodes($w));
        $this->assertClean($w);
    }

    public function testNoOtherAssumptionAppearsWithIt(): void
    {
        $w = $this->walker();

        $w->run('Order::find($id)');

        $this->assertSame([], $this->gapCodes($w), 'an assumption is not a gap');
    }
}
