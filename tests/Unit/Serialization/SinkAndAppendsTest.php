<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Serialization;

/**
 * The serialisation points (returning models, response()->json(), toArray/toJson/jsonSerialize, json_encode) and
 * what they set off: the $appends accessors of every serialised model, old style and new, and the models inside
 * the relations that were loaded.
 */
final class SinkAndAppendsTest extends SerializationCase
{
    /** @return array<string, array{0: string}> */
    public static function perRowAccessors(): array
    {
        return [
            'new style Attribute::get' => ['appendsNew'],
            'new style Attribute::make(get:)' => ['appendsMake'],
            'old style getXxxAttribute' => ['appendsOld'],
            'returning the models from the action' => ['sinkReturnModels'],
            'models inside a literal array' => ['sinkJsonArrayShape'],
            'Collection::toArray()' => ['sinkToArray'],
            'json_encode' => ['sinkJsonEncode'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('perRowAccessors')]
    public function testTheAccessorRunsOncePerSerialisedRowSoItsLazyLoadIsPerRow(string $action): void
    {
        $s = $this->summary($action);

        $this->assertSame(['select orders', 'lazy_load customers in orders'], $this->trace($s), $action);
        $this->assertSame(['query' => 1, 'memory' => 1], $this->degrees($s), $action);
        $this->assertSame([], $this->gapDescriptions($s), $action);
        $this->assertNoUnknownWithoutGap();
    }

    /** @return array<string, array{0: string}> */
    public static function singleModels(): array
    {
        return [
            'response()->json(model)' => ['appendsSingle'],
            'returning one model' => ['sinkReturnModel'],
            'toJson()' => ['sinkToJson'],
            'jsonSerialize()' => ['sinkJsonSerialize'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('singleModels')]
    public function testOneSerialisedModelRunsTheAccessorOnce(string $action): void
    {
        $s = $this->summary($action);

        $this->assertSame(['select orders', 'lazy_load customers'], $this->trace($s), $action);
        $this->assertSame(['query' => 0, 'memory' => 0], $this->degrees($s), $action);
        $this->assertSame([], $this->gapDescriptions($s), $action);
        $this->assertNoUnknownWithoutGap();
    }

    public function testAccessorOverModelsLoadedWithTheirCustomerReadsNothingMore(): void
    {
        $s = $this->summary('appendsEagerNoLazy');

        $this->assertSame(['select orders', 'eager_load customers'], $this->trace($s));
        $this->assertSame(['query' => 0, 'memory' => 1], $this->degrees($s));
        $this->assertSame([], $this->gapDescriptions($s));
        $this->assertNoUnknownWithoutGap();
    }

    public function testAnAccessorOnAModelReachedThroughALoadedRelationStillRunsForEveryRow(): void
    {
        $s = $this->summary('appendsNestedRelation');

        $this->assertSame(['select orders', 'eager_load customers', 'lazy_load orders in orders'], $this->trace($s));
        $this->assertSame(['query' => 1, 'memory' => 2], $this->degrees($s));
        $this->assertSame([], $this->gapDescriptions($s));
        $this->assertNoUnknownWithoutGap();
    }

    public function testRowsOfALoadedToManyRelationAreLoopedAndTheirAccessorsRun(): void
    {
        $s = $this->summary('appendsRelationRows');

        $this->assertSame(['select orders', 'eager_load items', 'lazy_load products in orders>items'], $this->trace($s));
        $this->assertSame(['query' => 2, 'memory' => 2], $this->degrees($s));
        $this->assertSame('orders', $this->events($s)[2]->frames[1]->origin->perTable);
        $this->assertSame([], $this->gapDescriptions($s));
        $this->assertNoUnknownWithoutGap();
    }

    public function testARelationThatWasNotLoadedIsNotSerialised(): void
    {
        $s = $this->summary('appendsRelationRowsNotLoaded');

        $this->assertSame(['select orders'], $this->trace($s));
        $this->assertSame(['query' => 0, 'memory' => 1], $this->degrees($s));
        $this->assertSame([], $this->gapDescriptions($s));
    }

    public function testAnAppendsListThatIsNotAConstantIsAGapOnBothAxes(): void
    {
        $s = $this->summary('appendsUnresolved');

        $this->assertSame(['MODEL_PROPERTY_UNRESOLVED'], $this->gapCodes($s));
        $this->assertSame(['query', 'memory'], array_map(static fn ($a): string => $a->value, $s->gaps[0]->affects));
        $this->assertNoUnknownWithoutGap();
    }

    public function testAnAppendedNameWithoutAnAccessorIsAGap(): void
    {
        $s = $this->summary('appendsGhost');

        $this->assertSame(['ACCESSOR_NOT_ANALYZED'], $this->gapCodes($s));
        $this->assertNoUnknownWithoutGap();
    }

    public function testModelsThatAreNeverSerialisedRunNoAccessor(): void
    {
        $s = $this->summary('sinkNothingSerialized');

        $this->assertSame(['select orders'], $this->trace($s));
        $this->assertSame(['query' => 0, 'memory' => 1], $this->degrees($s));
        $this->assertSame([], $this->gapDescriptions($s));
    }

    public function testResponseJsonOfPlainDataRecordsNothing(): void
    {
        $s = $this->summary('sinkPlainJson');

        $this->assertSame([], $s->events);
        $this->assertSame([], $this->gapDescriptions($s));
        $this->assertNoUnknownWithoutGap();
    }

    public function testEverySerialisationIsCountedNotOnlyTheFirst(): void
    {
        $s = $this->summary('sinkTwiceIsTwo');

        $this->assertSame(['select orders', 'lazy_load customers in orders', 'lazy_load customers in orders'], $this->trace($s));
        $this->assertNoUnknownWithoutGap();
    }

    public function testAnAccessorAlsoRunsWhenItsAttributeIsOnlyRead(): void
    {
        $s = $this->summary('plainReadAccessor');

        $this->assertSame(['select orders', 'lazy_load customers in orders'], $this->trace($s));
        $this->assertSame(['query' => 1, 'memory' => 1], $this->degrees($s));
        $this->assertSame([], $this->gapDescriptions($s));
        $this->assertNoUnknownWithoutGap();
    }
}
