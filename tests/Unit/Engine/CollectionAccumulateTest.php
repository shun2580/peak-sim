<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Engine;

use PeakSim\Analysis\Contracts\CallHandler;
use PeakSim\Analysis\Contracts\CallSite;
use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Contracts\HandlerRegistry;
use PeakSim\Analysis\Engine\Engine;
use PeakSim\Analysis\Engine\EngineOptions;
use PeakSim\Analysis\Type\ClosureT;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Model\Axis;
use PeakSim\Model\Cardinality;
use PeakSim\Model\CostEvent;
use PeakSim\Model\Delivery;
use PeakSim\Model\EventKind;
use PeakSim\Model\Frame;
use PeakSim\Model\Origin;
use PeakSim\Model\Summary;
use PeakSim\Source\AppSourceIndex;
use PeakSim\Tests\Unit\Engine\Support\FakeBindingTable;
use PeakSim\Tests\Unit\Engine\Support\FakeModelCatalog;
use PeakSim\Tests\Unit\Engine\Support\StubKnownHandler;
use PeakSim\Tests\Unit\Engine\Support\TempApp;
use PhpParser\Node\Expr;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A Collection that collects while a stream is walked is held in memory exactly as `$rows[] = v` is (design 1.4,
 * the accumulation heuristic): push / add / prepend / put on the variable, and `$x = $x->merge(..)` / concat.
 * The handlers here are bare stand-ins that hand the list back; the counting is the engine's.
 */
final class CollectionAccumulateTest extends TestCase
{
    private ?TempApp $app = null;

    /** @return array<string, array{string}> a statement that collects $order into $kept */
    public static function collectingStatements(): array
    {
        return [
            'push' => ['$kept->push($order);'],
            'add' => ['$kept->add($order);'],
            'prepend' => ['$kept->prepend($order);'],
            'put' => ['$kept->put(\'key\', $order);'],
            'merge' => ['$kept = $kept->merge([$order]);'],
            'concat' => ['$kept = $kept->concat([$order]);'],
        ];
    }

    #[DataProvider('collectingStatements')]
    public function testCollectingInAStreamedLoopCountsLikeAnArrayAppend(string $statement): void
    {
        $array = $this->walk('$kept = [];', '$kept[] = $order;', streamed: true);
        $collection = $this->walk('$kept = collect();', $statement, streamed: true);

        self::assertCount(1, $this->accumulations($collection));
        self::assertSame($this->shapeOf($array), $this->shapeOf($collection));
        self::assertSame(['query' => 0, 'memory' => 1], \PeakSim\Analysis\Engine\CandidateComparison::degrees($collection));
        self::assertSame([], $collection->gaps);
    }

    #[DataProvider('collectingStatements')]
    public function testCollectingBelowAPlainLoopGrowsTheMemoryDegreeByTheFramesAndAddsNoEvent(string $statement): void
    {
        $array = $this->walk('$kept = [];', '$kept[] = $order;', streamed: false);
        $collection = $this->walk('$kept = collect();', $statement, streamed: false);

        self::assertSame([], $this->accumulations($collection), 'only a streamed loop is the accumulation heuristic');
        self::assertSame([], $collection->assumptions);
        self::assertSame($this->shapeOf($array), $this->shapeOf($collection));
    }

    public function testTheCollectionKeepsGrowingWithTheLoopItWasCollectedIn(): void
    {
        $summary = $this->walk('$kept = collect();', '$kept->push($order);', streamed: false, after: 'foreach ($kept as $each) { Order::first(); }');

        self::assertSame(1, \PeakSim\Analysis\Engine\CandidateComparison::degrees($summary)['query'], 'one read per collected row');
    }

    public function testCollectingARowSetCountsOneMoreDegreeForTheRowsHeld(): void
    {
        $array = $this->walk('$kept = [];', '$kept[] = Order::all();', streamed: true);
        $collection = $this->walk('$kept = collect();', '$kept->push(Order::all());', streamed: true);

        self::assertSame($this->shapeOf($array), $this->shapeOf($collection));
        self::assertSame(2, \PeakSim\Analysis\Engine\CandidateComparison::degrees($collection)['memory']);
    }

    public function testMergingIntoAnotherVariableIsNotAccumulation(): void
    {
        $summary = $this->walk('$kept = collect(); $both = collect();', '$both = $kept->merge([$order]);', streamed: true);

        self::assertSame([], $this->accumulations($summary));
        self::assertSame([], $summary->assumptions);
    }

    public function testAMergeWhoseResultIsThrownAwayIsNotAccumulation(): void
    {
        $summary = $this->walk('$kept = collect();', '$kept->merge([$order]);', streamed: true);

        self::assertSame([], $this->accumulations($summary));
    }

    public function testACollectionMadeInsideTheLoopStaysInsideIt(): void
    {
        $summary = $this->walk('', '$kept = collect(); $kept->push($order);', streamed: true);

        self::assertSame([], $this->accumulations($summary), 'declared in the loop: nothing outlives one round');
    }

    public function testAMethodOfAnotherNameOnTheCollectionIsNotAccumulation(): void
    {
        $summary = $this->walk('$kept = collect();', '$kept->values();', streamed: true);

        self::assertSame([], $this->accumulations($summary));
    }

    public function testCollectingFromAVariableThatIsNotAListIsLeftAlone(): void
    {
        $summary = $this->walk('$kept = $this;', '$kept->push($order);', streamed: true);

        self::assertSame([], $this->accumulations($summary));
    }

    public function testTheArgumentOfAPushIsStillWalked(): void
    {
        $summary = $this->walk('$kept = collect();', '$kept->push(Order::first());', streamed: true);

        $reads = array_filter($summary->events, static fn (CostEvent $e): bool => $e->kind !== EventKind::Accumulate && $e->query !== null);
        self::assertCount(1, $reads, 'the read inside push() is not lost');
    }

    /**
     * @return list<CostEvent>
     */
    private function accumulations(Summary $s): array
    {
        return array_values(array_filter($s->events, static fn (CostEvent $e): bool => $e->kind === EventKind::Accumulate));
    }

    /** What the Judge would see of the accumulation: how deep it holds, from where, and the assumption made. */
    private function shapeOf(Summary $s): array
    {
        return [
            array_map(static fn (CostEvent $e): array => [$e->memory?->ownDegree, $e->memory?->ownOrigin?->table, $e->memory?->retainFrom, count($e->frames)], $this->accumulations($s)),
            array_map(static fn ($a): array => [$a->code->value, array_map(static fn (Axis $x): string => $x->value, $a->affects)], $s->assumptions),
            \PeakSim\Analysis\Engine\CandidateComparison::degrees($s),
        ];
    }

    private function walk(string $declare, string $statement, bool $streamed, string $after = ''): Summary
    {
        $registry = new HandlerRegistry();
        $registry->register(new StubKnownHandler(), 30);
        $registry->register(new class ($streamed) implements CallHandler {
            public function __construct(private readonly bool $streamed)
            {
            }

            public function handles(CallSite $c, EvalContext $ctx): bool
            {
                $listCall = $c->node instanceof Expr\MethodCall && $c->receiver instanceof ListT
                    && in_array($c->name, ['push', 'add', 'prepend', 'put', 'merge', 'concat', 'values'], true);

                return $listCall || in_array($c->name, ['stream_orders', 'collect', 'first', 'all'], true);
            }

            public function handle(CallSite $c, EvalContext $ctx): Type
            {
                if ($c->receiver instanceof ListT) {
                    return $c->receiver;
                }
                if ($c->name === 'collect') {
                    return new ListT(new PlainT(), Cardinality::bounded());
                }
                if ($c->name === 'first') {
                    $ctx->emit(new \PeakSim\Model\CostEvent(EventKind::Select, $ctx->site($c->node), [], new \PeakSim\Model\QueryPart(false, null), null, 'orders', [], $ctx->seq()));

                    return new ModelT('App\Models\Order');
                }
                if ($c->name === 'all') {
                    return new ListT(new ModelT('App\Models\Order'), Cardinality::table('orders'), Delivery::Materialized);
                }
                $callback = $c->argType(0);
                assert($callback instanceof ClosureT);
                $window = new ListT(new ModelT('App\Models\Order'), Cardinality::table('orders'), $this->streamed ? Delivery::Streamed : Delivery::Materialized, true);

                return $ctx->withFrame(new Frame(new Origin('orders'), $this->streamed, $ctx->site($c->node)), fn (): Type => $ctx->invokeClosure($callback, [$window]));
            }
        }, 5);

        $this->app = new TempApp(['app/Act.php' => <<<PHP
            <?php
            namespace App;
            use App\Models\Order;
            class Act
            {
                public function run()
                {
                    {$declare}
                    stream_orders(function (\$orders) use (&\$kept) {
                        foreach (\$orders as \$order) {
                            {$statement}
                        }
                    });
                    {$after}
                }
            }
            PHP]);
        $engine = new Engine(
            new AppSourceIndex($this->app->root),
            new FakeModelCatalog(['App\Models\Order' => ['table' => 'orders']]),
            new FakeBindingTable(),
            $registry,
            new EngineOptions(auditUnknown: true),
        );

        return $engine->analyzeAction('App\Act', 'run');
    }
}
