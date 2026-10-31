<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Engine;

use PeakSim\Analysis\Contracts\CallHandler;
use PeakSim\Analysis\Contracts\CallSite;
use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Contracts\HandlerRegistry;
use PeakSim\Analysis\Engine\Engine;
use PeakSim\Analysis\Engine\EngineOptions;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Model\EventKind;
use PeakSim\Model\Summary;
use PeakSim\Source\AppSourceIndex;
use PeakSim\Tests\Unit\Engine\Support\FakeBindingTable;
use PeakSim\Tests\Unit\Engine\Support\FakeModelCatalog;
use PeakSim\Tests\Unit\Engine\Support\StubKnownHandler;
use PeakSim\Tests\Unit\Engine\Support\StubQueryHandler;
use PeakSim\Tests\Unit\Engine\Support\TempApp;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `$orders->load('customer')` changes the variable $orders: what the handler gives back (the same kind of value
 * with the relation now loaded) replaces the variable's type from there on (design 1.5, flow-sensitive by variable).
 * The handler here is a stand-in that marks the relation named by the first argument as loaded.
 */
final class LoadRebindTest extends TestCase
{
    private ?TempApp $app = null;

    /** @return array<string, array{string}> */
    public static function loadingMethods(): array
    {
        return [
            'load' => ['load'],
            'loadMissing' => ['loadMissing'],
            'loadCount' => ['loadCount'],
            'loadSum' => ['loadSum'],
            'loadMax' => ['loadMax'],
            'loadMin' => ['loadMin'],
            'loadAvg' => ['loadAvg'],
            'loadExists' => ['loadExists'],
            'loadMorph' => ['loadMorph'],
        ];
    }

    #[DataProvider('loadingMethods')]
    public function testTheVariableOfAListHoldsTheListTheHandlerGaveBack(string $method): void
    {
        $summary = $this->walk("\$orders = Order::get(); \$orders->{$method}('customer'); foreach (\$orders as \$o) { \$o->customer; }");

        self::assertSame([], $this->lazyLoads($summary), "after {$method}() the customer is there");
    }

    #[DataProvider('loadingMethods')]
    public function testTheVariableOfARowHoldsTheRowTheHandlerGaveBack(string $method): void
    {
        $summary = $this->walk("\$order = Order::first(); \$order->{$method}('customer'); \$order->customer;");

        self::assertSame([], $this->lazyLoads($summary));
    }

    public function testWithoutTheLoadTheReadsAreLazy(): void
    {
        $summary = $this->walk('$orders = Order::get(); foreach ($orders as $o) { $o->customer; }');

        self::assertCount(1, $this->lazyLoads($summary));
    }

    public function testALoadAfterTheLoopDoesNotHelpTheLoop(): void
    {
        $summary = $this->walk("\$orders = Order::get(); foreach (\$orders as \$o) { \$o->customer; } \$orders->load('customer');");

        self::assertCount(1, $this->lazyLoads($summary), 'flow-sensitive: the loop ran before the load');
    }

    public function testTheLoadedRelationsAddUp(): void
    {
        $summary = $this->walk("\$orders = Order::get(); \$orders->load('customer'); \$orders->load('items'); foreach (\$orders as \$o) { \$o->customer; \$o->items; }");

        self::assertSame([], $this->lazyLoads($summary));
    }

    public function testALoadInOneBranchIsNotAssumedAfterTheBranch(): void
    {
        $summary = $this->walk("\$orders = Order::get(); if (\$flag) { \$orders->load('customer'); } foreach (\$orders as \$o) { \$o->customer; }");

        self::assertCount(1, $this->lazyLoads($summary), 'only what every branch loaded is loaded (TypeMerge)');
    }

    public function testALoadInBothBranchesIsLoaded(): void
    {
        $summary = $this->walk("\$orders = Order::get(); if (\$flag) { \$orders->load('customer'); } else { \$orders->loadMissing('customer'); } foreach (\$orders as \$o) { \$o->customer; }");

        self::assertSame([], $this->lazyLoads($summary));
    }

    public function testALoadInsideALoopBodyIsKept(): void
    {
        $summary = $this->walk("\$orders = Order::get(); foreach ([1, 2] as \$i) { \$orders->load('customer'); } foreach (\$orders as \$o) { \$o->customer; }");

        self::assertCount(1, $this->lazyLoads($summary), 'a loop body may run zero times: the merge with the state before it applies');
    }

    public function testAnotherVariableIsNotTouched(): void
    {
        $summary = $this->walk("\$orders = Order::get(); \$others = Order::get(); \$orders->load('customer'); foreach (\$others as \$o) { \$o->customer; }");

        self::assertCount(1, $this->lazyLoads($summary));
    }

    public function testAHandlerThatGivesBackSomethingElseLeavesTheVariableAlone(): void
    {
        $summary = $this->walk("\$orders = Order::get(); \$orders->touch('customer'); foreach (\$orders as \$o) { \$o->customer; }");

        self::assertCount(1, $this->lazyLoads($summary), 'touch() is not a loading method');
    }

    public function testAReceiverThatIsNotAVariableIsNotRebound(): void
    {
        $summary = $this->walk("\$orders = Order::get(); \$orders->first()?->load('customer'); foreach (\$orders as \$o) { \$o->customer; }");

        self::assertCount(1, $this->lazyLoads($summary));
    }

    /** @return list<\PeakSim\Model\CostEvent> */
    private function lazyLoads(Summary $s): array
    {
        return array_values(array_filter($s->events, static fn ($e): bool => $e->kind === EventKind::LazyLoad));
    }

    private function walk(string $statements): Summary
    {
        $registry = new HandlerRegistry();
        $registry->register(new class () implements CallHandler {
            private const NAMES = ['load', 'loadmissing', 'loadcount', 'loadsum', 'loadmax', 'loadmin', 'loadavg', 'loadexists', 'loadmorph', 'touch'];

            public function handles(CallSite $c, EvalContext $ctx): bool
            {
                return $c->node instanceof Expr\MethodCall && ($c->receiver instanceof ListT || $c->receiver instanceof ModelT)
                    && in_array(strtolower($c->name), self::NAMES, true);
            }

            public function handle(CallSite $c, EvalContext $ctx): Type
            {
                if (strtolower($c->name) === 'touch') {
                    return new PlainT();
                }
                $name = $c->argNode(0);
                $names = $name instanceof Scalar\String_ ? [$name->value] : [];
                $receiver = $c->receiver;
                if ($receiver instanceof ListT && $receiver->elem instanceof ModelT) {
                    return $receiver->withElem($receiver->elem->withEager($names));
                }
                assert($receiver instanceof ModelT);

                return $receiver->withEager($names);
            }
        }, 5);
        $registry->register(new StubQueryHandler(), 10);
        $registry->register(new StubKnownHandler(), 30);

        $this->app = new TempApp(['app/Act.php' => <<<PHP
            <?php
            namespace App;
            use App\Models\Order;
            class Act
            {
                public function run(\$flag)
                {
                    {$statements}
                }
            }
            PHP]);
        $engine = new Engine(
            new AppSourceIndex($this->app->root),
            new FakeModelCatalog([
                'App\Models\Order' => ['table' => 'orders', 'relations' => ['customer' => ['App\Models\Customer', false], 'items' => ['App\Models\Item', true]]],
                'App\Models\Customer' => ['table' => 'customers'],
                'App\Models\Item' => ['table' => 'items'],
            ]),
            new FakeBindingTable(),
            $registry,
            new EngineOptions(auditUnknown: true),
        );
        $summary = $engine->analyzeAction('App\Act', 'run');
        self::assertSame([], $engine->violations());

        return $summary;
    }
}
