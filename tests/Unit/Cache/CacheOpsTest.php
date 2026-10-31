<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Cache;

use PeakSim\Analysis\Handlers\Cache\CacheOps;
use PeakSim\Analysis\Type\ClosureT;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\ObjectT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\ShapeT;
use PeakSim\Analysis\Type\UnionT;
use PeakSim\Analysis\Type\UnknownT;
use PeakSim\Model\Axis;
use PeakSim\Model\Cardinality;
use PeakSim\Model\CardinalityKind;
use PeakSim\Model\EventKind;
use PeakSim\Model\MemoryCause;
use PeakSim\Model\ScenarioTag;
use PeakSim\Tests\Unit\Known\KnownCase;
use PeakSim\Tests\Unit\Known\KnownWalker;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Cache::remember and its kin: the closure runs for the miss; the hit restores what the closure returned.
 * Each test is named after the mini-app (k-cache-*) it stands in for.
 */
final class CacheOpsTest extends KnownCase
{
    private function table(string $name, string $per = ''): ListT
    {
        return new ListT(new ModelT('App\\Models\\Order'), Cardinality::table($name, $per === '' ? null : $per));
    }

    // ----- k-cache-remember / k-cache-forever -------------------------------------------------------------

    public function testRememberRunsTheClosureForTheMissAndRestoresTheListOnTheHit(): void
    {
        $w = $this->cache();

        $t = $w->run("Cache::remember('orders.names', 300, fn () => Order::get())");

        $this->assertSame([
            'select orders q=1 m=1:orders:materialize s=miss',
            'cache_restore orders m=1:orders:cache_restore s=hit',
        ], $this->events($w));
        $this->assertSame([], $this->gapCodes($w));
        $this->assertSame([], $w->unhandled);
        $this->assertClean($w);

        $this->assertInstanceOf(ListT::class, $t);
        $this->assertSame(CardinalityKind::Table, $t->card->kind);
        $this->assertSame('orders', $t->card->origin?->table);
    }

    public function testTheRestoreEventIsMemoryOnlyAndCarriesTheHitTag(): void
    {
        $w = $this->cache();

        $w->run("Cache::remember('k', 300, fn () => Order::get())");

        [$miss, $restore] = $w->fake->events();
        $this->assertSame(EventKind::CacheRestore, $restore->kind);
        $this->assertNull($restore->query);
        $this->assertNotNull($restore->memory);
        $this->assertSame(1, $restore->memory->ownDegree);
        $this->assertSame('orders', $restore->memory->ownOrigin?->table);
        $this->assertSame(MemoryCause::CacheRestore, $restore->memory->cause);
        $this->assertNull($restore->memory->retainFrom);
        $this->assertSame(['hit'], $this->sides($restore->scenario));
        $this->assertSame(['miss'], $this->sides($miss->scenario));
        $this->assertSame($miss->scenario[0]->cacheSite, $restore->scenario[0]->cacheSite, 'one cache call, one id');
        $this->assertGreaterThan($miss->seq, $restore->seq);
    }

    public function testTheDriverAssumptionBelongsToTheHitViewAndTheMemoryAxisOnly(): void
    {
        $w = $this->cache();

        $w->run("Cache::remember('k', 300, fn () => Order::get())");

        $this->assertSame(['CACHE_DRIVER_SERIALIZING'], $this->assumptionCodes($w));
        $assumption = $w->fake->assumptions()[0];
        $this->assertSame(['hit'], $this->sides($assumption->scenario));
        $this->assertSame([Axis::Memory], $assumption->affects);
    }

    /** @return array<string, array{0: string}> */
    public static function spellings(): array
    {
        return [
            'remember' => ["Cache::remember('k', 300, fn () => Order::get())"],
            'rememberForever' => ["Cache::rememberForever('k', fn () => Order::get())"],
            'sear' => ["Cache::sear('k', fn () => Order::get())"],
            'flexible' => ["Cache::flexible('k', [60, 120], fn () => Order::get())"],
            'the global alias' => ["GlobalCache::remember('k', 300, fn () => Order::get())"],
            'the helper' => ["\$cache->remember('k', 300, fn () => Order::get())"],
            'an injected repository' => ["\$repo->rememberForever('k', fn () => Order::get())"],
            'a named callback' => ["Cache::remember(key: 'k', ttl: 300, callback: fn () => Order::get())"],
            'named in any order' => ["Cache::remember(callback: fn () => Order::get(), ttl: 300, key: 'k')"],
        ];
    }

    #[DataProvider('spellings')]
    public function testEverySpellingOfRememberGivesTheSameCostAsRemember(string $php): void
    {
        $w = $this->cache();

        $w->run($php);

        $this->assertSame([
            'select orders q=1 m=1:orders:materialize s=miss',
            'cache_restore orders m=1:orders:cache_restore s=hit',
        ], $this->events($w), $php);
        $this->assertSame([], $this->gapCodes($w), $php);
        $this->assertSame([], $w->unhandled, $php);
    }

    public function testTheHelperThatReturnsTheManagerIsFollowed(): void
    {
        $w = $this->cache();

        $w->run("cache()->remember('k', 300, fn () => Order::get())");

        $this->assertSame(['select orders q=1 m=1:orders:materialize s=miss', 'cache_restore orders m=1:orders:cache_restore s=hit'], $this->events($w));
        $this->assertSame([], $this->gapCodes($w));
    }

    // ----- k-known-facade ----------------------------------------------------------------------------------

    public function testACachedScalarRestoresNothingThatGrows(): void
    {
        $w = $this->cache();

        $t = $w->run("Cache::remember('orders.count', 60, fn () => Order::count())");

        $this->assertSame(['aggregate orders q=1 s=miss', 'cache_restore - s=hit'], $this->events($w));
        $this->assertNull($w->fake->events()[1]->memory);
        $this->assertSame([], $this->assumptionCodes($w), 'nothing grows, so there is no driver to assume');
        $this->assertSame([], $this->gapCodes($w));
        $this->assertInstanceOf(PlainT::class, $t);
    }

    // ----- k-cache-in-loop ---------------------------------------------------------------------------------

    public function testACacheLookupInALoopKeepsTheLoopOnBothSidesButRestoresNothingInFull(): void
    {
        $w = $this->cache(['id' => new PlainT()]);

        $w->fake->withFrame(KnownWalker::frame('orders'), function () use ($w): void {
            $w->run("Cache::remember('customer.name.'.\$id, 300, fn () => Customer::first())");
        });

        $this->assertSame([
            'select customers q=1 f=orders s=miss',
            'cache_restore - f=orders s=hit',
        ], $this->events($w));
        $this->assertNull($w->fake->events()[1]->memory, 'one string per row is not a table-sized restore');
        $this->assertSame([], $this->assumptionCodes($w));
    }

    public function testACachedListInALoopRestoresAListPerRow(): void
    {
        $w = $this->cache();

        $w->fake->withFrame(KnownWalker::frame('customers'), function () use ($w): void {
            $w->run("Cache::remember('k', 300, fn () => Order::get())");
        });

        $this->assertSame([
            'select orders q=1 m=1:orders:materialize f=customers s=miss',
            'cache_restore orders m=1:orders:cache_restore f=customers s=hit',
        ], $this->events($w));
    }

    // ----- k-cache-closure-unknown-gap ---------------------------------------------------------------------

    public function testAClosureThatCannotBeFollowedGapsTheMissAndTheHitSize(): void
    {
        $w = $this->cache();

        $t = $w->run("Cache::remember('rows', 300, fn () => undefined_helper())");

        $this->assertInstanceOf(UnknownT::class, $t);
        $this->assertSame(['VENDOR_CALL_NOT_MODELLED', 'CACHE_VALUE_SIZE_UNKNOWN'], $this->gapCodes($w));
        [$inner, $size] = $w->fake->gaps();
        $this->assertSame(['miss'], $this->sides($inner->scenario));
        $this->assertSame(['hit'], $this->sides($size->scenario));
        $this->assertSame([Axis::Memory], $size->affects, 'a hit runs no query; only what it restores is unknown');
        $this->assertSame(['cache_restore - s=hit'], $this->events($w));
        $this->assertClean($w);
    }

    /** @return array<string, array{0: string}> */
    public static function notAClosure(): array
    {
        return [
            'a function name' => ["Cache::remember('k', 60, 'strlen')"],
            'an array callable' => ["Cache::remember('k', 60, [\$this, 'load'])"],
            'no callback at all' => ["Cache::remember('k', 60)"],
        ];
    }

    #[DataProvider('notAClosure')]
    public function testACallbackThatIsNotAClosureIsAGapOnBothSides(string $php): void
    {
        $w = $this->cache();

        $t = $w->run($php);

        $this->assertInstanceOf(UnknownT::class, $t);
        $this->assertSame(['UNRESOLVED_CALL', 'CACHE_VALUE_SIZE_UNKNOWN'], $this->gapCodes($w));
        $this->assertSame(['miss'], $this->sides($w->fake->gaps()[0]->scenario));
        $this->assertSame(['hit'], $this->sides($w->fake->gaps()[1]->scenario));
        $this->assertClean($w);
    }

    public function testAClosureHeldInAVariableIsRun(): void
    {
        $w = $this->cache();
        $w->vars['loader'] = $w->run('fn () => Order::count()');
        $this->assertInstanceOf(ClosureT::class, $w->vars['loader']);

        $w->run("Cache::remember('k', 60, \$loader)");

        $this->assertSame(['aggregate orders q=1 s=miss', 'cache_restore - s=hit'], $this->events($w));
    }

    // ----- what the closure returns decides what a hit restores --------------------------------------------

    /** @return array<string, array{0: \PeakSim\Analysis\Type\Type, 1: int, 2: string|null, 3: bool}> value, degree, origin, size unknown */
    public static function returned(): array
    {
        $orders = new ListT(new ModelT('App\\Models\\Order'), Cardinality::table('orders'));
        $items = new ListT(new ModelT('App\\Models\\Order'), Cardinality::table('items', 'orders'));

        return [
            'a plain value' => [new PlainT(), 0, null, false],
            'one row' => [new ModelT('App\\Models\\Order'), 0, null, false],
            'a bounded list' => [new ListT(new PlainT(), Cardinality::bounded()), 0, null, false],
            'a table-sized list' => [$orders, 1, 'orders', false],
            'a table-sized list of ids' => [new ListT(new PlainT(), Cardinality::table('customers')), 1, 'customers', false],
            'a list of table-sized lists' => [new ListT($items, Cardinality::table('orders')), 2, 'orders', false],
            'a bounded list of table-sized lists' => [new ListT($orders, Cardinality::bounded()), 1, 'orders', false],
            'a shape holding a table-sized list' => [new ShapeT(['total' => new PlainT(), 'orders' => $orders]), 1, 'orders', false],
            'a union with a table-sized member' => [new UnionT([new PlainT(), $orders]), 1, 'orders', false],
            'a list of unknown size' => [new ListT(new PlainT(), Cardinality::unknown()), 0, null, true],
            'an object whose size is not known' => [new ObjectT('App\\Services\\Report'), 0, null, true],
            'a shape holding a list of unknown size' => [new ShapeT(['rows' => new ListT(new PlainT(), Cardinality::unknown())]), 0, null, true],
        ];
    }

    #[DataProvider('returned')]
    public function testTheSizeOfWhatIsReturnedDecidesTheRestore(\PeakSim\Analysis\Type\Type $value, int $degree, ?string $origin, bool $unknown): void
    {
        $w = $this->cache(['value' => $value]);

        $t = $w->run("Cache::remember('k', 60, fn () => \$value)");

        $restore = array_values(array_filter($w->fake->events(), static fn ($e): bool => $e->kind === EventKind::CacheRestore));
        $this->assertCount(1, $restore);
        $this->assertSame($degree, $restore[0]->memory?->ownDegree ?? 0);
        $this->assertSame($origin, $restore[0]->memory?->ownOrigin?->table);
        $this->assertSame($unknown ? ['CACHE_VALUE_SIZE_UNKNOWN'] : [], $this->gapCodes($w));
        $this->assertSame($degree > 0 ? ['CACHE_DRIVER_SERIALIZING'] : [], $this->assumptionCodes($w));
        $this->assertSame($value, $t, 'the type of the closure is the type of the call');
        $this->assertClean($w);
    }

    public function testTheTypeStaysWithItsOriginSoALaterLoopSeesIt(): void
    {
        $w = $this->cache();

        $t = $w->run("Cache::remember('k', 60, fn () => Order::get())");

        $this->assertInstanceOf(ListT::class, $t);
        $this->assertSame('orders', $t->card->origin?->table);
        $this->assertSame('App\\Models\\Order', $t->elem instanceof ModelT ? $t->elem->class : null);
    }

    // ----- what is evaluated, and when ---------------------------------------------------------------------

    public function testTheKeyAndTheLifetimeAreEvaluatedOnceAndOutsideBothSides(): void
    {
        $w = $this->cache();

        $w->run('Cache::remember(Order::count(), Customer::count(), fn () => 1)');

        $this->assertSame(['aggregate orders q=1', 'aggregate customers q=1', 'cache_restore - s=hit'], $this->events($w));
    }

    public function testTheClosureRunsExactlyOnce(): void
    {
        $w = $this->cache();

        $w->run("Cache::remember('k', 60, fn () => Order::count())");

        $this->assertSame(1, $w->closuresRun);
    }

    // ----- the id of a cache call --------------------------------------------------------------------------

    public function testTheSameCallInTheSamePlaceKeepsItsId(): void
    {
        $w = $this->cache();
        $call = $w->parse("Cache::remember('k', 60, fn () => 1)");

        $w->eval($call);
        $w->eval($call);

        $ids = array_map(static fn ($e): int => $e->scenario[0]->cacheSite, $w->fake->events());
        $this->assertCount(2, $ids);
        $this->assertSame($ids[0], $ids[1]);
    }

    public function testTwoCallsHaveTwoIds(): void
    {
        $w = $this->cache();

        $w->run("[Cache::remember('a', 60, fn () => 1), Cache::remember('b', 60, fn () => 1)]");

        $ids = array_map(static fn ($e): int => $e->scenario[0]->cacheSite, $w->fake->events());
        $this->assertCount(2, array_unique($ids));
    }

    public function testTheSamePositionInTwoFilesHasTwoIds(): void
    {
        $a = new KnownWalker([new CacheOps()], [], [], 'app/A.php');
        $b = new KnownWalker([new CacheOps()], [], [], 'app/B.php');

        $a->run("Cache::remember('k', 60, fn () => 1)");
        $b->run("Cache::remember('k', 60, fn () => 1)");

        $this->assertNotSame($a->fake->events()[0]->scenario[0]->cacheSite, $b->fake->events()[0]->scenario[0]->cacheSite);
    }

    public function testTheTagIsAlwaysAMissOrAHitOfTheSameId(): void
    {
        $w = $this->cache();

        $w->run("Cache::remember('k', 60, fn () => Order::get())");

        foreach ($w->fake->events() as $e) {
            $this->assertCount(1, $e->scenario);
            $this->assertContains($e->scenario[0]->side, [ScenarioTag::MISS, ScenarioTag::HIT]);
        }
    }

    // ----- what CacheOps leaves alone ----------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function notRemember(): array
    {
        return [
            'get' => ["Cache::get('k')"],
            'put' => ["Cache::put('k', 1, 60)"],
            'forever' => ["Cache::forever('k', 1)"],
            'store' => ["Cache::store('redis')"],
            'remember on an app object' => ["\$report->remember('k', 60, fn () => 1)"],
            'remember on a plain value' => ["\$plain->remember('k', 60, fn () => 1)"],
            'a property called remember' => ['$cache->remember'],
            'remember on a rows list' => ["\$rows->remember('k', 60, fn () => 1)"],
        ];
    }

    #[DataProvider('notRemember')]
    public function testOnlyTheRememberFamilyOnTheCacheIsTaken(string $php): void
    {
        $w = $this->with([new CacheOps()]);
        $w->vars = [
            'cache' => new ObjectT(self::CACHE_MANAGER),
            'report' => new ObjectT('App\\Services\\Report'),
            'plain' => new PlainT(),
            'rows' => $this->table('orders'),
        ];

        $w->run($php);

        $this->assertCount(1, $w->unhandled, $php);
        $this->assertSame([], array_values(array_filter($w->fake->events(), static fn ($e): bool => $e->scenario !== [])), $php);
    }

    public function testTheHandlerAndTheTableAgreeOnWhoOwnsWhat(): void
    {
        $table = \PeakSim\Analysis\Handlers\Known\KnownTable::default();

        foreach (CacheOps::METHODS as $method => $position) {
            $this->assertGreaterThanOrEqual(1, $position);
            foreach ([CacheOps::FACADE, 'Cache'] as $facade) {
                $this->assertSame('CacheOps', $table->forStatic($facade, $method)?->delegate, "{$facade}::{$method}");
            }
            foreach (CacheOps::RECEIVERS as $class) {
                $this->assertSame('CacheOps', $table->forMethod($class, $method)?->delegate, "{$class}::{$method}");
            }
        }
        $this->assertSame('object:' . CacheOps::RECEIVERS[0], $table->forFunction('cache')?->returns);
    }
}
