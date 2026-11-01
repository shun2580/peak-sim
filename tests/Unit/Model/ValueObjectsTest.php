<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Model;

use PeakSim\Model\Axis;
use PeakSim\Model\Cardinality;
use PeakSim\Model\CardinalityKind;
use PeakSim\Model\CostEvent;
use PeakSim\Model\Delivery;
use PeakSim\Model\EventKind;
use PeakSim\Model\Frame;
use PeakSim\Model\Gap;
use PeakSim\Model\GapCode;
use PeakSim\Model\Growth;
use PeakSim\Model\MemoryCause;
use PeakSim\Model\MemoryPart;
use PeakSim\Model\Origin;
use PeakSim\Model\QueryPart;
use PeakSim\Model\ScenarioTag;
use PeakSim\Model\Site;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValueObjectsTest extends TestCase
{
    /** @return iterable<string, array{int, Growth}> */
    public static function degrees(): iterable
    {
        yield '0' => [0, Growth::Constant];
        yield '1' => [1, Growth::Linear];
        yield '2' => [2, Growth::Nested];
        yield '3 is still O(N*M)' => [3, Growth::Nested];
        yield '10' => [10, Growth::Nested];
    }

    #[DataProvider('degrees')]
    public function testGrowthFromDegree(int $degree, Growth $expected): void
    {
        self::assertSame($expected, Growth::fromDegree($degree));
    }

    public function testGrowthRejectsNegativeDegree(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Growth::fromDegree(-1);
    }

    public function testGrowthLabels(): void
    {
        self::assertSame(['O(1)', 'O(N)', 'O(N*M)', 'unknown'], array_map(static fn (Growth $g): string => $g->value, Growth::cases()));
    }

    public function testValueObjectsAreImmutable(): void
    {
        $site = new Site('app/A.php', 3);

        $this->expectException(\Error::class);
        $site->line = 4;
    }

    public function testEveryValueObjectClassIsFinalAndReadonly(): void
    {
        foreach ([Site::class, Origin::class, Cardinality::class, Frame::class, QueryPart::class, MemoryPart::class, ScenarioTag::class, CostEvent::class, Gap::class] as $class) {
            $ref = new \ReflectionClass($class);
            self::assertTrue($ref->isFinal(), "$class must be final");
            self::assertTrue($ref->isReadOnly(), "$class must be readonly");
        }
    }

    public function testFingerprintsOfEqualValuesAreEqualAndOfDifferentValuesDiffer(): void
    {
        self::assertSame((new Site('a.php', 1))->fingerprint(), (new Site('a.php', 1, 'note ignored'))->fingerprint());
        self::assertNotSame((new Site('a.php', 1))->fingerprint(), (new Site('a.php', 2))->fingerprint());
        self::assertNotSame((new Origin('orders'))->fingerprint(), (new Origin('customers'))->fingerprint());
        self::assertNotSame((new Origin('items', 'orders'))->fingerprint(), (new Origin('items'))->fingerprint());
        self::assertNotSame((new Origin(null))->fingerprint(), (new Origin('orders'))->fingerprint());
    }

    public function testCardinalityFactories(): void
    {
        self::assertSame(CardinalityKind::Bounded, Cardinality::bounded()->kind);
        self::assertNull(Cardinality::bounded()->origin);
        self::assertSame(CardinalityKind::Unknown, Cardinality::unknown()->kind);

        $table = Cardinality::table('items', 'orders');
        self::assertSame(CardinalityKind::Table, $table->kind);
        self::assertSame('items', $table->origin?->table);
        self::assertSame('orders', $table->origin?->perTable);

        $fingerprints = [Cardinality::bounded()->fingerprint(), Cardinality::unknown()->fingerprint(), Cardinality::table('orders')->fingerprint(), Cardinality::table('customers')->fingerprint(), Cardinality::table(null)->fingerprint()];
        self::assertSame($fingerprints, array_values(array_unique($fingerprints)));
    }

    public function testScenarioTagAcceptsOnlyMissOrHit(): void
    {
        self::assertSame('miss', (new ScenarioTag(1, ScenarioTag::MISS))->side);
        self::assertSame('hit', (new ScenarioTag(1, ScenarioTag::HIT))->side);

        $this->expectException(\InvalidArgumentException::class);
        new ScenarioTag(1, 'maybe');
    }

    public function testCostEventWithOuterFramesPutsTheCallersLoopsFirstAndKeepsTheRest(): void
    {
        $outer = new Frame(new Origin('orders'), false, new Site('app/Outer.php', 10));
        $inner = new Frame(new Origin('items', 'orders'), true, new Site('app/Inner.php', 20));
        $event = $this->event([$inner]);

        $moved = $event->withOuterFrames([$outer]);

        self::assertSame([$outer, $inner], $moved->frames);
        self::assertSame([$inner], $event->frames, 'the original event is not changed');
        self::assertSame($event->kind, $moved->kind);
        self::assertSame($event->site, $moved->site);
        self::assertSame($event->query, $moved->query);
        self::assertSame($event->memory, $moved->memory);
        self::assertSame($event->seq, $moved->seq);
    }

    public function testCostEventWithOuterScenarioPutsTheCallersTagsFirst(): void
    {
        $outerTag = new ScenarioTag(1, ScenarioTag::MISS);
        $ownTag = new ScenarioTag(2, ScenarioTag::HIT);
        $event = new CostEvent(EventKind::Select, new Site('a.php', 1), [], new QueryPart(), null, 'orders', [$ownTag], 0);

        self::assertSame([$outerTag, $ownTag], $event->withOuterScenario([$outerTag])->scenario);
    }

    public function testCostEventFingerprintIsStableAndSeesFramesAndSeq(): void
    {
        $frame = new Frame(new Origin('orders'), false, new Site('app/A.php', 5));
        $a = $this->event([$frame], 0);

        self::assertSame($a->fingerprint(), $this->event([$frame], 0)->fingerprint());
        self::assertNotSame($a->fingerprint(), $this->event([], 0)->fingerprint());
        self::assertNotSame($a->fingerprint(), $this->event([$frame], 1)->fingerprint());
    }

    public function testGapFingerprintTellsApartTwoCausesAtTheSameSiteAndCode(): void
    {
        $site = new Site('app/A.php', 5);
        $one = new Gap(GapCode::UNRESOLVED_CALL, $site, 'alpha() is not declared', [Axis::Query, Axis::Memory], 1, []);
        $same = new Gap(GapCode::UNRESOLVED_CALL, $site, 'alpha() is not declared', [Axis::Query, Axis::Memory], 1, []);
        $otherCause = new Gap(GapCode::UNRESOLVED_CALL, $site, 'beta() is not declared', [Axis::Query, Axis::Memory], 1, []);
        $otherCode = new Gap(GapCode::RECURSION, $site, 'alpha() is not declared', [Axis::Query, Axis::Memory], 1, []);

        self::assertSame($one->fingerprint(), $same->fingerprint(), 'the same cause is the same gap');
        self::assertNotSame($one->fingerprint(), $otherCause->fingerprint(), 'a different cause on the same line is a different gap');
        self::assertNotSame($one->fingerprint(), $otherCode->fingerprint());
    }

    public function testEnumValuesAreUniqueAndKeepTheDocumentedLabels(): void
    {
        self::assertSame(['select', 'aggregate', 'write', 'lazy_load', 'eager_load', 'payload', 'cache_restore', 'accumulate', 'pdo_buffer'], array_map(static fn (EventKind $k): string => $k->value, EventKind::cases()));
        self::assertSame(['materialize', 'lazy_to_many', 'eager_to_many', 'pdo_buffered', 'payload', 'cache_restore', 'accumulate'], array_map(static fn (MemoryCause $c): string => $c->value, MemoryCause::cases()));
        self::assertSame(['materialized', 'streamed', 'pdo_buffered'], array_map(static fn (Delivery $d): string => $d->value, Delivery::cases()));
        self::assertSame(['bounded', 'table', 'unknown'], array_map(static fn (CardinalityKind $k): string => $k->value, CardinalityKind::cases()));
        self::assertSame(['query', 'memory'], array_map(static fn (Axis $a): string => $a->value, Axis::cases()));
    }

    /** @param list<Frame> $frames */
    private function event(array $frames, int $seq = 0): CostEvent
    {
        return new CostEvent(
            EventKind::LazyLoad,
            new Site('app/E.php', 7),
            $frames,
            new QueryPart(),
            new MemoryPart(1, new Origin('items'), MemoryCause::LazyToMany),
            'items',
            [],
            $seq,
        );
    }
}
