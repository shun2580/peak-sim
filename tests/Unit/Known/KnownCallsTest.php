<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Known;

use PeakSim\Analysis\Handlers\Known\KnownTable;
use PeakSim\Analysis\Type\BuilderT;
use PeakSim\Analysis\Type\ClosureT;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\ObjectT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\UnionT;
use PeakSim\Analysis\Type\UnknownT;
use PeakSim\Model\Cardinality;
use PeakSim\Model\GapCode;
use PHPUnit\Framework\Attributes\DataProvider;

/** KnownCalls: a call on the allow-list is a plain value; every other vendor call is a gap, never a "safe". */
final class KnownCallsTest extends KnownCase
{
    private const MISSING = 'VENDOR_CALL_NOT_MODELLED';

    private function rows(): ListT
    {
        return new ListT(new ModelT('App\\Models\\Order'), Cardinality::table('orders'));
    }

    /** @return array<string, array{0: string}> */
    public static function knownFunctions(): array
    {
        return [
            'now' => ['now()'],
            'now with a timezone' => ["now('UTC')"],
            'round' => ['round(1.5, 2)'],
            'count of a table-sized list' => ['count($rows)'],
            'count of an array literal' => ['count([1, 2, 3])'],
            'in_array strict' => ["in_array('new', ['new', 'paid'], true)"],
            'abort_unless' => ['abort_unless(true, 404)'],
            'abort_unless with a message' => ["abort_unless(true, 403, 'no')"],
        ];
    }

    #[DataProvider('knownFunctions')]
    public function testKnownFunctionsGiveAPlainValueWithoutAGap(string $php): void
    {
        $w = $this->known(['rows' => $this->rows()]);

        $t = $w->run($php);

        $this->assertInstanceOf(PlainT::class, $t);
        $this->assertSame([], $this->gapCodes($w));
        $this->assertSame([], $w->unhandled);
        $this->assertSame([], $w->fake->events());
        $this->assertSame([], $this->assumptionCodes($w));
        $this->assertClean($w);
    }

    public function testResponseWithoutArgumentsIsTheFactory(): void
    {
        $w = $this->known();

        $t = $w->run('response()');

        $this->assertEquals(new ObjectT(self::RESPONSE_FACTORY), $t);
        $this->assertSame([], $this->gapCodes($w));
        $this->assertClean($w);
    }

    public function testResponseWithContentMayBeSerialisedSoItIsAGap(): void
    {
        $w = $this->known(['rows' => $this->rows()]);

        $t = $w->run('response($rows, 200)');

        $this->assertInstanceOf(UnknownT::class, $t);
        $this->assertSame([self::MISSING], $this->gapCodes($w));
        $this->assertClean($w);
    }

    public function testTheCacheHelperIsTheManagerOnlyWithoutArguments(): void
    {
        $w = $this->known();

        $this->assertEquals(new ObjectT(self::CACHE_MANAGER), $w->run('cache()'));
        $this->assertSame([], $this->gapCodes($w));

        $this->assertInstanceOf(UnknownT::class, $w->run("cache('orders.count')"));
        $this->assertSame([self::MISSING], $this->gapCodes($w));
        $this->assertClean($w);
    }

    public function testTheQueryStringOfARequestIsPlain(): void
    {
        $w = $this->known(['request' => new ObjectT(self::REQUEST)]);

        $this->assertInstanceOf(PlainT::class, $w->run("\$request->query('via')"));
        $this->assertInstanceOf(PlainT::class, $w->run("\$request->query('via', 'x')"));
        $this->assertSame([], $this->gapCodes($w));
        $this->assertSame([], $w->unhandled);
        $this->assertClean($w);
    }

    public function testOtherRequestMethodsAreNotOnTheList(): void
    {
        $w = $this->known(['request' => new ObjectT(self::REQUEST)]);

        $this->assertInstanceOf(UnknownT::class, $w->run('$request->query()'));
        $this->assertInstanceOf(UnknownT::class, $w->run("\$request->input('via')"));
        $this->assertInstanceOf(UnknownT::class, $w->run('$request->all()'));
        $this->assertSame([self::MISSING, self::MISSING, self::MISSING], $this->gapCodes($w));
        $this->assertClean($w);
    }

    public function testAttributeGetHandsBackItsClosureWithoutRunningIt(): void
    {
        $w = $this->known();

        $t = $w->run('Attribute::get(fn () => Order::get())');

        $this->assertInstanceOf(ClosureT::class, $t);
        $this->assertSame(0, $w->closuresRun);
        $this->assertSame([], $w->fake->events());
        $this->assertSame([], $this->gapCodes($w));
        $this->assertClean($w);
    }

    /** @return array<string, array{0: string, 1: string|null}> source, the static method the returned getter calls (null = no getter) */
    public static function attributes(): array
    {
        return [
            'get' => ['Attribute::get(fn () => Order::get())', 'get'],
            'get, named' => ['Attribute::get(get: fn () => Order::get())', 'get'],
            'make with a getter first' => ['Attribute::make(fn () => Order::get(), fn () => Customer::count())', 'get'],
            'make with a getter by name' => ['Attribute::make(get: fn () => Order::get(), set: fn () => Customer::count())', 'get'],
            'make with the names the other way round' => ['Attribute::make(set: fn () => Customer::count(), get: fn () => Order::get())', 'get'],
            'make with a getter alone' => ['Attribute::make(get: fn () => Order::get())', 'get'],
            'make with a setter alone' => ['Attribute::make(set: fn () => Customer::count())', null],
            'make with nothing' => ['Attribute::make()', null],
        ];
    }

    #[DataProvider('attributes')]
    public function testAnAccessorHandsBackItsGetterAndNeverRunsAClosure(string $php, ?string $getter): void
    {
        $w = $this->known();

        $t = $w->run($php);

        if ($getter === null) {
            $this->assertInstanceOf(PlainT::class, $t);
        } else {
            $this->assertInstanceOf(ClosureT::class, $t);
            $this->assertInstanceOf(\PhpParser\Node\Expr\ArrowFunction::class, $t->node);
            $this->assertSame($getter, $t->node->expr->name->toString());
        }
        $this->assertSame(0, $w->closuresRun);
        $this->assertSame([], $w->fake->events());
        $this->assertSame([], $this->gapCodes($w));
        $this->assertSame([], $w->unhandled);
        $this->assertClean($w);
    }

    public function testAttributeMakeTakesNoMoreThanTwoArguments(): void
    {
        $w = $this->known();

        $this->assertInstanceOf(UnknownT::class, $w->run('Attribute::make(fn () => 1, fn () => 2, fn () => 3)'));
        $this->assertSame([self::MISSING], $this->gapCodes($w));
    }

    public function testAClosureIsNotAcceptedWhereTheEntryDoesNotStoreIt(): void
    {
        $w = $this->known();

        $t = $w->run('round(fn () => 1)');

        $this->assertInstanceOf(UnknownT::class, $t);
        $this->assertSame([self::MISSING], $this->gapCodes($w));
        $this->assertSame(0, $w->closuresRun);
        $this->assertClean($w);
    }

    public function testAFacadeThatIsNotListedIsAGapNotSafe(): void
    {
        $w = $this->known();

        $t = $w->run("Storage::disk('s3')");

        $this->assertInstanceOf(UnknownT::class, $t);
        $this->assertSame([self::MISSING], $this->gapCodes($w));
        $this->assertStringContainsString('not on the allow-list', $this->gapMessages($w)[0]);
        $this->assertClean($w);
    }

    public function testAFunctionThatIsNotListedIsAGapNotSafe(): void
    {
        $w = $this->known();

        $this->assertInstanceOf(UnknownT::class, $w->run('strlen("abc")'));
        $this->assertInstanceOf(UnknownT::class, $w->run('undefined_helper()'));
        $this->assertSame([self::MISSING, self::MISSING], $this->gapCodes($w));
        $this->assertClean($w);
    }

    public function testAMethodOnAVendorObjectThatIsNotListedIsAGap(): void
    {
        $w = $this->known(['disk' => new ObjectT('Illuminate\\Contracts\\Filesystem\\Filesystem')]);

        $this->assertInstanceOf(UnknownT::class, $w->run("\$disk->put('a.txt', 'x')"));
        $this->assertSame([self::MISSING], $this->gapCodes($w));
        $this->assertClean($w);
    }

    public function testTheArgumentsOfAGappedCallAreStillEvaluatedOnce(): void
    {
        $w = $this->known();

        $w->run("Storage::put('a.txt', Order::count())");

        $this->assertSame(['aggregate orders q=1'], $this->events($w));
        $this->assertSame([self::MISSING], $this->gapCodes($w));
    }

    public function testTheArgumentsOfAKnownCallAreEvaluatedOnce(): void
    {
        $w = $this->known();

        $w->run('round(Order::count(), 2)');

        $this->assertSame(['aggregate orders q=1'], $this->events($w));
        $this->assertSame([], $this->gapCodes($w));
    }

    public function testAnArgumentTheFunctionCouldCallBackIntoRefusesTheFunction(): void
    {
        $unknownArg = $this->known(['report' => new ObjectT('App\\Services\\Report'), 'builder' => new BuilderT('App\\Models\\Order', 'orders')]);

        $this->assertInstanceOf(UnknownT::class, $unknownArg->run('count($report)'));
        $this->assertInstanceOf(UnknownT::class, $unknownArg->run('count($builder)'));
        $this->assertSame([self::MISSING, self::MISSING], $this->gapCodes($unknownArg));
        $this->assertStringContainsString('could call back', $this->gapMessages($unknownArg)[0]);
        $this->assertClean($unknownArg);
    }

    public function testAUnionWithOneRiskyMemberIsRefused(): void
    {
        $w = $this->known(['either' => new UnionT([new PlainT(), new ObjectT('App\\Services\\Report')])]);

        $this->assertInstanceOf(UnknownT::class, $w->run('count($either)'));
        $this->assertSame([self::MISSING], $this->gapCodes($w));
        $this->assertClean($w);
    }

    public function testAnArgumentThatIsAlreadyUnknownAddsNoGapOfItsOwn(): void
    {
        $w = $this->known();
        $w->vars['unknown'] = $w->ctx->gap(GapCode::UNRESOLVED_CALL, $w->parse('1'), 'made earlier');

        $t = $w->run('count($unknown)');

        $this->assertInstanceOf(PlainT::class, $t);
        $this->assertSame(['UNRESOLVED_CALL'], $this->gapCodes($w), 'only the gap that made the unknown');
        $this->assertClean($w);
    }

    /** @return array<string, array{0: string}> */
    public static function badArity(): array
    {
        return [
            'now with three' => ['now(1, 2, 3)'],
            'round with none' => ['round()'],
            'in_array with one' => ["in_array('x')"],
            'abort_unless with one' => ['abort_unless(true)'],
            'count with three' => ['count([1], 1, 2)'],
        ];
    }

    #[DataProvider('badArity')]
    public function testACallOutsideTheListedArityIsAGap(string $php): void
    {
        $w = $this->known();

        $this->assertInstanceOf(UnknownT::class, $w->run($php));
        $this->assertSame([self::MISSING], $this->gapCodes($w));
        $this->assertClean($w);
    }

    public function testUnpackedArgumentsHaveNoKnownArityAndAreRefused(): void
    {
        $w = $this->known(['args' => $this->rows()]);

        $this->assertInstanceOf(UnknownT::class, $w->run('count(...$args)'));
        $this->assertSame([self::MISSING], $this->gapCodes($w));
        $this->assertClean($w);
    }

    /** @return array<string, array{0: string}> */
    public static function ownedByOthers(): array
    {
        return [
            'view helper' => ["view('orders.index')"],
            'json sink' => ['$factory->json([])'],
            'view on the factory' => ["\$factory->view('orders.index')"],
            'cache facade remember' => ["Cache::remember('k', 60, fn () => 1)"],
            'cache facade forever' => ["Cache::rememberForever('k', fn () => 1)"],
            'cache manager' => ["\$manager->remember('k', 60, fn () => 1)"],
            'cache repository' => ["\$repo->flexible('k', [1, 2], fn () => 1)"],
        ];
    }

    #[DataProvider('ownedByOthers')]
    public function testWhatAnotherHandlerOwnsIsLeftForIt(string $php): void
    {
        $w = $this->with([new \PeakSim\Analysis\Handlers\Known\KnownCalls()]);
        $w->vars = [
            'factory' => new ObjectT(self::RESPONSE_FACTORY),
            'manager' => new ObjectT(self::CACHE_MANAGER),
            'repo' => new ObjectT(self::CACHE_REPOSITORY),
        ];

        $w->run($php);

        $this->assertCount(1, $w->unhandled, 'the allow-list must decline, not answer');
    }

    public function testAppClassesAreLeftToTheEngine(): void
    {
        $w = $this->known(['report' => new ObjectT('App\\Services\\Report')]);

        $w->run('Report::build()');
        $w->run('$report->run()');

        $this->assertCount(2, $w->unhandled);
        $this->assertSame('App\\Services\\Report::build', $w->unhandled[0]);
        $this->assertSame(['UNRESOLVED_CALL', 'UNRESOLVED_CALL'], $this->gapCodes($w), 'only the walker\'s own fall-through, none from the allow-list');
    }

    public function testAVendorTypeTheAppBindsToItsOwnClassIsLeftToTheEngine(): void
    {
        $w = $this->known(['request' => new ObjectT(self::REQUEST)], [self::REQUEST => ['App\\Http\\SafeRequest']]);

        $w->run("\$request->query('via')");

        $this->assertCount(1, $w->unhandled);
    }

    public function testReceiversThatAreNotObjectsAreLeftToTheEngine(): void
    {
        $w = $this->known(['plain' => new PlainT(), 'rows' => $this->rows()]);

        $w->run("\$plain->format('Y')");
        $w->run('$rows->count()');

        $this->assertCount(2, $w->unhandled);
    }

    public function testAPropertyIsNotACall(): void
    {
        $w = $this->known(['request' => new ObjectT(self::REQUEST)]);

        $w->run('$request->query');

        $this->assertCount(1, $w->unhandled);
    }

    public function testTheSameCallGivesTheSameAnswerTwice(): void
    {
        $a = $this->known();
        $b = $this->known();

        $a->run("round(count([1]), 2) + strlen('x')");
        $b->run("round(count([1]), 2) + strlen('x')");

        $this->assertEquals($a->fake->gaps(), $b->fake->gaps());
    }

    public function testATableCanBeHandedInAndThenGovernsTheAnswers(): void
    {
        $table = KnownTable::fromArray([
            'functions' => [
                'slug' => [
                    'returns' => 'plain',
                    'basis' => 'contract',
                    'why' => 'A string helper: it reads nothing and calls nothing back.',
                ],
            ],
        ]);
        $w = $this->known([], [], $table);

        $this->assertInstanceOf(PlainT::class, $w->run("slug('a b')"));
        $this->assertInstanceOf(UnknownT::class, $w->run('now()'));
        $this->assertSame([self::MISSING], $this->gapCodes($w));
    }
}
