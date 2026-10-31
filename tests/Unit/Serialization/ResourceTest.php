<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Serialization;

use PeakSim\Model\EventKind;

/**
 * JsonResource and ResourceCollection: collection()/make()/new, $this as the wrapped model, whenLoaded() that never
 * loads, nested resources, collection classes, parent::toArray().
 */
final class ResourceTest extends SerializationCase
{
    public function testCollectionRunsToArrayOncePerRowAndTheRelationReadIsALazyLoadPerRow(): void
    {
        $s = $this->summary('resourceCollection');

        $this->assertSame(['query' => 1, 'memory' => 1], $this->degrees($s));
        $this->assertSame(['select orders', 'lazy_load customers in orders'], $this->trace($s));
        $this->assertSame([], $this->gapDescriptions($s));
        $this->assertNoUnknownWithoutGap();
    }

    public function testMakeAndNewWrapOneModelSoTheRelationReadIsOneQuery(): void
    {
        foreach (['resourceMake', 'resourceNew'] as $action) {
            $s = $this->summary($action);

            $this->assertSame(['query' => 0, 'memory' => 0], $this->degrees($s), $action);
            $this->assertSame(['select orders', 'lazy_load customers'], $this->trace($s), $action);
            $this->assertSame([], $this->gapDescriptions($s), $action);
            $this->assertNoUnknownWithoutGap();
        }
    }

    public function testWhenLoadedNeverStartsALazyLoad(): void
    {
        $s = $this->summary('resourceWhenLoaded');

        $this->assertSame(['query' => 0, 'memory' => 1], $this->degrees($s));
        $this->assertSame(['select orders'], $this->trace($s));
        $this->assertSame([], $this->gapDescriptions($s));
        $this->assertNoUnknownWithoutGap();
    }

    public function testWhenLoadedOnAnEagerLoadedRelationCostsNothingMore(): void
    {
        $s = $this->summary('resourceWhenLoadedEager');

        $this->assertSame(['query' => 0, 'memory' => 1], $this->degrees($s));
        $this->assertSame(['select orders', 'eager_load customers'], $this->trace($s));
        $this->assertSame([], $this->gapDescriptions($s));
        $this->assertNoUnknownWithoutGap();
    }

    public function testWhatWhenLoadedHandsBackIsSerialisedToo(): void
    {
        // The loaded customer is an AppendedCustomer: its $appends accessor reads `orders` lazily, once per row.
        $s = $this->summary('resourceWhenLoadedAppended');

        $this->assertSame(['query' => 1, 'memory' => 2], $this->degrees($s));
        $this->assertContains('lazy_load orders in orders', $this->trace($s));
        $this->assertSame([], $this->gapDescriptions($s));
        $this->assertNoUnknownWithoutGap();
    }

    public function testWhenLoadedClosureRunsOnlyWhenTheRelationIsLoaded(): void
    {
        $notLoaded = $this->summary('resourceWhenLoadedClosureNotLoaded');
        $this->assertSame(['select orders'], $this->trace($notLoaded));
        $this->assertSame(['query' => 0, 'memory' => 1], $this->degrees($notLoaded));

        $loaded = $this->summary('resourceWhenLoadedClosureLoaded');
        // The closure gets the loaded customer and reads its orders: a lazy to-many load for every row.
        $this->assertContains('lazy_load orders in orders', $this->trace($loaded));
        $this->assertSame(['query' => 1, 'memory' => 2], $this->degrees($loaded));
        $this->assertSame([], $this->gapDescriptions($loaded));
        $this->assertNoUnknownWithoutGap();
    }

    public function testWhenAndMergeWhenClosuresAreRunOnce(): void
    {
        $s = $this->summary('resourceConditional');

        $this->assertSame(['select orders', 'lazy_load customers in orders', 'lazy_load items in orders'], $this->trace($s));
        $this->assertSame(['query' => 1, 'memory' => 2], $this->degrees($s));
        $this->assertSame([], $this->gapDescriptions($s));
        $this->assertNoUnknownWithoutGap();
    }

    public function testANestedResourceOverAToManyRelationIsOneLoopInsideTheOther(): void
    {
        $s = $this->summary('resourceNested');

        $this->assertSame(['select orders', 'lazy_load items in orders', 'lazy_load products in orders>items'], $this->trace($s));
        $this->assertSame(['query' => 2, 'memory' => 2], $this->degrees($s));
        $deepest = $this->events($s, EventKind::LazyLoad)[1];
        $this->assertSame('orders', $deepest->frames[1]->origin->perTable, 'the items are per order');
        $this->assertSame([], $this->gapDescriptions($s));
        $this->assertNoUnknownWithoutGap();
    }

    public function testACollectionClassNamesItsElementResourceByCollects(): void
    {
        $s = $this->summary('resourceCollectionClass');

        $this->assertSame(['select orders', 'lazy_load customers in orders'], $this->trace($s));
        $this->assertSame(['query' => 1, 'memory' => 1], $this->degrees($s));
        $this->assertSame([], $this->gapDescriptions($s));
        $this->assertNoUnknownWithoutGap();
    }

    public function testACollectionClassWithoutCollectsIsFoundByItsName(): void
    {
        $s = $this->summary('resourceCollectionNamed');

        $this->assertSame(['select orders', 'lazy_load customers in orders'], $this->trace($s));
        $this->assertSame([], $this->gapDescriptions($s));
        $this->assertNoUnknownWithoutGap();
    }

    public function testACollectionClassWhoseElementResourceCannotBeToldIsAGap(): void
    {
        $s = $this->summary('resourceCollectionUnknown');

        $this->assertSame(['RESOURCE_NOT_ANALYZED'], $this->gapCodes($s));
        $this->assertSame(['select orders'], $this->trace($s));
        $this->assertNoUnknownWithoutGap();
    }

    public function testACollectionClassWithItsOwnToArrayStillSerialisesEveryElement(): void
    {
        $s = $this->summary('resourceCollectionWrapped');

        $this->assertSame(['select orders', 'lazy_load customers in orders'], $this->trace($s));
        $this->assertSame(['query' => 1, 'memory' => 1], $this->degrees($s));
        $this->assertSame([], $this->gapDescriptions($s));
        $this->assertNoUnknownWithoutGap();
    }

    public function testParentToArraySerialisesTheModelAccessorsIncluded(): void
    {
        $s = $this->summary('resourceParentToArray');

        $this->assertSame(['select orders', 'lazy_load customers in orders'], $this->trace($s));
        $this->assertSame(['query' => 1, 'memory' => 1], $this->degrees($s));
        $this->assertSame([], $this->gapDescriptions($s));
        $this->assertNoUnknownWithoutGap();
    }

    public function testMethodsOfTheResourceAndThoseForwardedToTheModelAreFollowed(): void
    {
        $s = $this->summary('resourceForwarding');

        $this->assertContains('lazy_load customers in orders', $this->trace($s), 'the resource\'s own label() ran');
        $this->assertContains('aggregate items in orders', $this->trace($s), '$this->items()->count() went to the model');
        $this->assertSame(['RESOURCE_NOT_ANALYZED'], $this->gapCodes($s), 'shout() is neither the resource\'s nor the model\'s');
        $this->assertNoUnknownWithoutGap();
    }

    public function testResolveFiresTheSinkEvenWhenTheActionReturnsSomethingElse(): void
    {
        $s = $this->summary('resourceResolve');

        $this->assertSame(['select orders', 'lazy_load customers in orders'], $this->trace($s));
        $this->assertSame([], $this->gapDescriptions($s));
        $this->assertNoUnknownWithoutGap();
    }

    public function testResponseJsonOfAResourceAndAdditionalBothSerialiseItOnce(): void
    {
        foreach (['resourceViaResponseJson', 'resourceAdditional'] as $action) {
            $s = $this->summary($action);

            $this->assertSame(['select orders', 'lazy_load customers in orders'], $this->trace($s), $action);
            $this->assertSame([], $this->gapDescriptions($s), $action);
            $this->assertNoUnknownWithoutGap();
        }
    }

    public function testAResourceThatIsBuiltAndNeverSerialisedCostsNothing(): void
    {
        $s = $this->summary('resourceBuiltButNotReturned');

        $this->assertSame(['select orders'], $this->trace($s));
        $this->assertSame([], $this->gapDescriptions($s));
    }

    public function testAResourceThatContainsItselfStopsWithARecursionGap(): void
    {
        $s = $this->summary('resourceCycle');

        $this->assertContains('RECURSION', $this->gapCodes($s));
        $this->assertLessThan(10, count($s->events), 'the walk stopped, it did not unroll the cycle');
        $this->assertNoUnknownWithoutGap();
    }

    public function testACollectionOfUnknownSizeWhoseElementsDoSomethingIsALoopSourceGap(): void
    {
        $s = $this->summary('resourceUnknownSize');

        $this->assertSame(['LOOP_SOURCE_UNKNOWN'], $this->gapCodes($s));
        $this->assertNoUnknownWithoutGap();
    }

    public function testAPaginatedCollectionIsBoundedSoItAddsNoLoop(): void
    {
        $s = $this->summary('resourcePaginated');

        $this->assertSame(['query' => 0, 'memory' => 0], $this->degrees($s));
        $this->assertContains('lazy_load customers', $this->trace($s));
        $this->assertSame([], $this->gapDescriptions($s));
        $this->assertNoUnknownWithoutGap();
    }
}
