<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Collection;

use PeakSim\Analysis\Contracts\CallHandler;
use PeakSim\Analysis\Contracts\CallSite;
use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Contracts\HandlerRegistry;
use PeakSim\Analysis\Handlers\Collection\HigherOrderProxies;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\ShapeT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Model\Cardinality;
use PeakSim\Model\Delivery;
use PeakSim\Model\Frame;
use PeakSim\Tests\Support\FakeEvalContext;
use PeakSim\Tests\Unit\Collection\Support\Calls;
use PeakSim\Tests\Unit\Collection\Support\Probe;
use PHPUnit\Framework\TestCase;

/** $orders->each->save() and $orders->map->customer: a loop that is written as a property (c-hop-proxy). */
final class ProxyTest extends TestCase
{
    private function orders(Delivery $delivery = Delivery::Materialized): ListT
    {
        return new ListT(new ModelT('App\Models\Order'), Cardinality::table('orders'), $delivery);
    }

    public function testReadingAProxyNameOffAListMakesAProxy(): void
    {
        $handler = new HigherOrderProxies(new HandlerRegistry());
        $ctx = new FakeEvalContext();

        foreach (['each', 'map', 'filter', 'reject', 'sum', 'sortBy', 'groupBy', 'first', 'every', 'flatMap', 'keyBy', 'unique', 'partition', 'avg', 'max', 'min'] as $name) {
            self::assertTrue($handler->handles(Calls::property($this->orders(), $name), $ctx), $name);
        }
        self::assertFalse($handler->handles(Calls::property($this->orders(), 'items'), $ctx), 'not a proxy name');
        self::assertFalse($handler->handles(Calls::property(new ModelT('App\Models\Order'), 'each'), $ctx), 'a model is not a list');
    }

    public function testTheProxyRemembersTheListAndTheMethodItWillCall(): void
    {
        $handler = new HigherOrderProxies(new HandlerRegistry());
        $probe = new Probe();

        $proxy = $probe->run($handler, Calls::property($this->orders(), 'each'));

        self::assertInstanceOf(ShapeT::class, $proxy);
        self::assertSame(['proxy:each'], array_keys($proxy->entries));
        self::assertInstanceOf(ListT::class, $proxy->entries['proxy:each']);
        self::assertSame([], $probe->fake->events());
    }

    public function testACallOnTheProxyIsMadeOnEveryElementInsideTheLoop(): void
    {
        $seen = [];
        $inner = new class ($seen) implements CallHandler {
            /** @param list<array{string, Type, list<Frame>}> $seen */
            public function __construct(public array &$seen)
            {
            }

            public function handles(CallSite $c, EvalContext $ctx): bool
            {
                return $c->receiver instanceof ModelT && $c->name === 'save';
            }

            public function handle(CallSite $c, EvalContext $ctx): Type
            {
                $this->seen[] = [$c->name, $c->receiver, $ctx->frames()];

                return new PlainT();
            }
        };
        $registry = new HandlerRegistry();
        $registry->register($inner, 10);
        $handler = new HigherOrderProxies($registry);
        $probe = new Probe();
        $proxy = $probe->run($handler, Calls::property($this->orders(), 'each'));

        $result = $probe->run($handler, Calls::method($proxy, 'save()'));

        self::assertCount(1, $seen);
        self::assertSame('save', $seen[0][0]);
        self::assertInstanceOf(ModelT::class, $seen[0][1]);
        self::assertSame('orders', $seen[0][1]->origin?->table, 'the element knows its table');
        self::assertCount(1, $seen[0][2]);
        self::assertSame('orders', $seen[0][2][0]->origin->table);
        self::assertInstanceOf(ListT::class, $result, 'each gives the list back');
    }

    public function testAPropertyOfTheProxyIsReadOnEveryElementAndMapCollectsIt(): void
    {
        $inner = new class () implements CallHandler {
            public int $reads = 0;

            public function handles(CallSite $c, EvalContext $ctx): bool
            {
                return $c->receiver instanceof ModelT && $c->name === 'customer';
            }

            public function handle(CallSite $c, EvalContext $ctx): Type
            {
                $this->reads++;
                assert(count($ctx->frames()) === 1);

                return new ModelT('App\Models\Customer');
            }
        };
        $registry = new HandlerRegistry();
        $registry->register($inner, 10);
        $handler = new HigherOrderProxies($registry);
        $probe = new Probe();
        $proxy = $probe->run($handler, Calls::property($this->orders(), 'map'));

        $result = $probe->run($handler, Calls::property($proxy, 'customer'));

        self::assertSame(1, $inner->reads);
        self::assertInstanceOf(ListT::class, $result);
        self::assertInstanceOf(ModelT::class, $result->elem);
        self::assertSame('App\Models\Customer', $result->elem->class);
        self::assertSame('orders', $result->card->origin?->table);
    }

    public function testACallNobodyTakesOnAnAppModelIsAGapNotSilence(): void
    {
        $handler = new HigherOrderProxies(new HandlerRegistry());
        $probe = new Probe(null, new FakeEvalContext(closureInvoker: null));
        $proxy = $probe->run($handler, Calls::property($this->orders(), 'each'));

        $result = $probe->run($handler, Calls::method($proxy, 'mystery()'));

        self::assertNotEmpty($probe->fake->gaps());
        self::assertSame([], $probe->ctx->violations());
        self::assertInstanceOf(\PeakSim\Analysis\Type\UnknownT::class, $result);
    }

    public function testASumProxyGivesAPlainValue(): void
    {
        $inner = new class () implements CallHandler {
            public function handles(CallSite $c, EvalContext $ctx): bool
            {
                return $c->receiver instanceof ModelT;
            }

            public function handle(CallSite $c, EvalContext $ctx): Type
            {
                return new PlainT();
            }
        };
        $registry = new HandlerRegistry();
        $registry->register($inner, 10);
        $handler = new HigherOrderProxies($registry);
        $probe = new Probe();
        $proxy = $probe->run($handler, Calls::property($this->orders(), 'sum'));

        $result = $probe->run($handler, Calls::property($proxy, 'total'));

        self::assertInstanceOf(PlainT::class, $result);
    }
}
