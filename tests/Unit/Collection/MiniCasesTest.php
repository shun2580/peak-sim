<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Collection;

use PeakSim\Analysis\Contracts\HandlerRegistry;
use PeakSim\Analysis\Engine\CandidateComparison;
use PeakSim\Analysis\Engine\Engine;
use PeakSim\Analysis\Engine\EngineOptions;
use PeakSim\Analysis\Handlers\Collection\CollectionHandlers;
use PeakSim\Analysis\Handlers\Query\BuilderOps;
use PeakSim\Analysis\Handlers\Query\ModelStatic;
use PeakSim\Analysis\Handlers\Query\RelationOps;
use PeakSim\Model\AssumptionCode;
use PeakSim\Model\CostEvent;
use PeakSim\Model\EventKind;
use PeakSim\Model\Frame;
use PeakSim\Model\GapCode;
use PeakSim\Model\Summary;
use PeakSim\Source\AppSourceIndex;
use PeakSim\Tests\Unit\Engine\Support\FakeBindingTable;
use PeakSim\Tests\Unit\Engine\Support\FakeModelCatalog;
use PeakSim\Tests\Unit\Engine\Support\StubKnownHandler;
use PeakSim\Tests\Unit\Engine\Support\TempApp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The c-* mini-app situations (design 6.3), written out as the controller bodies and walked by the real engine with
 * the real Query handlers and these Collection handlers. The expected degrees are the ones in each case's expect.php.
 */
final class MiniCasesTest extends TestCase
{
    private ?Engine $engine = null;

    private ?TempApp $app = null;

    private function summary(string $body): Summary
    {
        $this->app = new TempApp(['app/Http/Controllers/TestController.php' => <<<PHP
            <?php
            namespace App\Http\Controllers;
            use App\Models\Order;
            class TestController
            {
                public function index()
                {
            {$body}
                }
            }
            PHP]);
        $registry = new HandlerRegistry();
        $ops = new BuilderOps();
        $registry->register(new ModelStatic($ops), 10);
        $registry->register($ops, 11);
        $registry->register(new RelationOps($ops), 12);
        CollectionHandlers::register($registry, 40);
        $registry->register(new StubKnownHandler(), 90);
        $this->engine = new Engine(
            new AppSourceIndex($this->app->root),
            new FakeModelCatalog([
                'App\Models\Order' => ['table' => 'orders', 'relations' => ['customer' => ['App\Models\Customer', false], 'items' => ['App\Models\Item', true]]],
                'App\Models\Customer' => ['table' => 'customers'],
                'App\Models\Item' => ['table' => 'items', 'relations' => ['product' => ['App\Models\Product', false]]],
                'App\Models\Product' => ['table' => 'products'],
            ]),
            new FakeBindingTable(),
            $registry,
            new EngineOptions(auditUnknown: true),
        );

        return $this->engine->analyzeAction('App\Http\Controllers\TestController', 'index');
    }

    /** @return array<string, array{string, int, int}> body, query degree, memory degree */
    public static function cases(): array
    {
        return [
            'c-map' => ['        $names = Order::get()->map(fn (Order $order) => $order->customer->name);' . "\n" . '        return response()->json($names);', 1, 1],
            'c-each' => ['        $total = 0;' . "\n" . '        Order::get()->each(function (Order $order) use (&$total): void { $total += $order->customer->id; });' . "\n" . '        return response()->json([\'total\' => $total]);', 1, 1],
            'c-filter' => ['        $kept = Order::get()->filter(fn (Order $order) => $order->customer->id > 1);' . "\n" . '        return response()->json($kept->values());', 1, 1],
            'c-reduce' => ['        $total = Order::get()->reduce(fn ($carry, Order $order) => $carry + $order->customer->id, 0);' . "\n" . '        return response()->json([\'total\' => $total]);', 1, 1],
            'c-array-map' => ['        $orders = Order::get()->all();' . "\n" . '        $names = array_map(fn (Order $order) => $order->customer->name, $orders);' . "\n" . '        return response()->json($names);', 1, 1],
            'c-array-filter' => ['        $orders = Order::get()->all();' . "\n" . '        $kept = array_filter($orders, fn (Order $order) => $order->customer->id > 1);' . "\n" . '        return response()->json(array_values($kept));', 1, 1],
            'c-usort' => ['        $orders = Order::get()->all();' . "\n" . '        usort($orders, fn (Order $a, Order $b) => $a->customer->id <=> $b->customer->id);' . "\n" . '        return response()->json($orders);', 1, 1],
            'c-hop-proxy' => ['        Order::get()->each->save();' . "\n" . '        return response()->json([\'done\' => true]);', 1, 1],
            'c-stream-materialize' => ['        $orders = Order::lazy(100)->all();' . "\n" . '        return response()->json(count($orders));', 1, 1],
            'c-flatmap' => ['        $ids = Order::get()->flatMap(fn (Order $order) => $order->items->pluck(\'id\'));' . "\n" . '        return response()->json($ids);', 1, 2],
            'q-load-after' => ['        $orders = Order::get();' . "\n" . '        $orders->load(\'customer\');' . "\n" . '        $names = [];' . "\n" . '        foreach ($orders as $order) {' . "\n" . '            $names[] = $order->customer->name;' . "\n" . '        }' . "\n" . '        return response()->json($names);', 0, 1],
            'q-load-missing' => ['        $orders = Order::get();' . "\n" . '        $orders->loadMissing(\'customer\');' . "\n" . '        $names = [];' . "\n" . '        foreach ($orders as $order) {' . "\n" . '            $names[] = $order->customer->name;' . "\n" . '        }' . "\n" . '        return response()->json($names);', 0, 1],
            'c-accumulate-nested' => ['        $all = [];' . "\n" . '        foreach (Order::get() as $order) {' . "\n" . '            foreach ($order->items as $item) {' . "\n" . '                $all[] = $item->id;' . "\n" . '            }' . "\n" . '        }' . "\n" . '        return response()->json($all);', 1, 2],
            'c-accumulate-in-chunk' => ['        $ids = [];' . "\n" . '        Order::chunk(100, function ($orders) use (&$ids): void {' . "\n" . '            foreach ($orders as $order) {' . "\n" . '                $ids[] = $order->id;' . "\n" . '            }' . "\n" . '        });' . "\n" . '        return response()->json($ids);', 1, 1],
            'c-accumulate-push-nested' => ['        $all = collect();' . "\n" . '        foreach (Order::get() as $order) {' . "\n" . '            foreach ($order->items as $item) {' . "\n" . '                $all->push($item->id);' . "\n" . '            }' . "\n" . '        }' . "\n" . '        return response()->json($all);', 1, 2],
            'c-accumulate-push-in-chunk' => ['        $ids = collect();' . "\n" . '        Order::chunk(100, function ($orders) use (&$ids): void {' . "\n" . '            foreach ($orders as $order) {' . "\n" . '                $ids->push($order->id);' . "\n" . '            }' . "\n" . '        });' . "\n" . '        return response()->json($ids);', 1, 1],
            'c-accumulate-add-in-lazy' => ['        $ids = collect();' . "\n" . '        foreach (Order::lazy(100) as $order) {' . "\n" . '            $ids->add($order->id);' . "\n" . '        }' . "\n" . '        return response()->json($ids);', 1, 1],
            'c-accumulate-put-in-lazy' => ['        $byId = collect();' . "\n" . '        foreach (Order::lazy(100) as $order) {' . "\n" . '            $byId->put($order->id, $order);' . "\n" . '        }' . "\n" . '        return response()->json($byId);', 1, 1],
            'c-accumulate-merge-in-chunk' => ['        $ids = collect();' . "\n" . '        Order::chunk(100, function ($orders) use (&$ids): void {' . "\n" . '            $ids = $ids->merge($orders->pluck(\'id\'));' . "\n" . '        });' . "\n" . '        return response()->json($ids);', 1, 2],
        ];
    }

    #[DataProvider('cases')]
    public function testTheDegreesAreTheOnesInTheCasesExpectation(string $body, int $query, int $memory): void
    {
        $summary = $this->summary($body);

        self::assertSame([], $summary->gaps, 'nothing was left unfollowed');
        self::assertNotEmpty($summary->events);
        self::assertSame(['query' => $query, 'memory' => $memory], CandidateComparison::degrees($summary));
        self::assertNotNull($this->engine);
        self::assertSame([], $this->engine->violations());
    }

    #[DataProvider('cases')]
    public function testEveryCaseIsAboutTheOrdersTable(string $body): void
    {
        $summary = $this->summary($body);

        $tables = array_values(array_unique(array_filter(array_map(
            static fn (CostEvent $e): ?string => $e->frames[0]->origin->table ?? $e->table,
            array_filter($summary->events, static fn (CostEvent $e): bool => $e->frames !== [] || $e->kind === EventKind::Select),
        ))));
        self::assertSame('orders', $tables[0] ?? null);
    }

    public function testFlatMapMultipliesMemoryByTheItemsPerOrder(): void
    {
        $summary = $this->summary($this->bodyOf('c-flatmap'));

        $lazy = array_values(array_filter($summary->events, static fn (CostEvent $e): bool => $e->kind === EventKind::LazyLoad));
        self::assertCount(1, $lazy);
        self::assertSame('orders', $lazy[0]->frames[0]->origin->table);
        self::assertSame('items', $lazy[0]->memory?->ownOrigin?->table);
        self::assertSame('orders', $lazy[0]->memory?->ownOrigin?->perTable);
    }

    public function testCollectingInsideAChunkCountsTheCollectedRowsAsMemory(): void
    {
        $summary = $this->summary($this->bodyOf('c-accumulate-in-chunk'));

        self::assertCount(1, array_filter($summary->events, static fn (CostEvent $e): bool => $e->kind === EventKind::Accumulate));
        self::assertContains(AssumptionCode::ACCUMULATION_HEURISTIC, array_map(static fn ($a) => $a->code, $summary->assumptions));
        $chunk = array_values(array_filter($summary->events, static fn (CostEvent $e): bool => $e->kind === EventKind::Select || $e->kind === EventKind::Aggregate));
        self::assertNotEmpty($chunk);
    }

    /** @return array<string, array{string, string}> a collection body and the array body it must count the same as */
    public static function collectingLikeAnArray(): array
    {
        $chunk = static fn (string $init, string $statement): string => '        ' . $init . "\n" . '        Order::chunk(100, function ($orders) use (&$ids): void {' . "\n" . '            foreach ($orders as $order) {' . "\n" . '                ' . $statement . "\n" . '            }' . "\n" . '        });' . "\n" . '        return response()->json($ids);';
        $window = static fn (string $init, string $statement): string => '        ' . $init . "\n" . '        Order::chunk(100, function ($orders) use (&$ids): void {' . "\n" . '            ' . $statement . "\n" . '        });' . "\n" . '        return response()->json($ids);';

        return [
            'push' => [$chunk('$ids = collect();', '$ids->push($order->id);'), $chunk('$ids = [];', '$ids[] = $order->id;')],
            'push of the models' => [$chunk('$ids = collect();', '$ids->push($order);'), $chunk('$ids = [];', '$ids[] = $order;')],
            'add' => [$chunk('$ids = collect();', '$ids->add($order->id);'), $chunk('$ids = [];', '$ids[] = $order->id;')],
            'prepend' => [$chunk('$ids = collect();', '$ids->prepend($order->id);'), $chunk('$ids = [];', '$ids[] = $order->id;')],
            'put' => [$chunk('$ids = collect();', '$ids->put($order->id, $order);'), $chunk('$ids = [];', '$ids[] = $order;')],
            'merge of a window' => [$window('$ids = collect();', '$ids = $ids->merge($orders->pluck(\'id\'));'), $window('$ids = [];', '$ids[] = $orders->pluck(\'id\');')],
            'concat of a window' => [$window('$ids = collect();', '$ids = $ids->concat($orders->pluck(\'id\'));'), $window('$ids = [];', '$ids[] = $orders->pluck(\'id\');')],
        ];
    }

    #[DataProvider('collectingLikeAnArray')]
    public function testCollectingThroughAMethodCountsLikeAppendingToAnArray(string $collection, string $array): void
    {
        $viaMethod = $this->summary($collection);
        $viaArray = $this->summary($array);

        self::assertSame([], $viaMethod->gaps);
        self::assertSame(CandidateComparison::degrees($viaArray), CandidateComparison::degrees($viaMethod));
        self::assertSame($this->accumulations($viaArray), $this->accumulations($viaMethod));
        self::assertCount(1, $this->accumulations($viaMethod));
        self::assertSame(
            array_map(static fn ($a): string => $a->code->value, $viaArray->assumptions),
            array_map(static fn ($a): string => $a->code->value, $viaMethod->assumptions),
        );
    }

    public function testCollectingWithAnotherMethodInTheSameLoopDoesNotAccumulate(): void
    {
        $summary = $this->summary("        \$ids = collect();\n        foreach (Order::lazy(100) as \$order) {\n            \$kept = \$ids->merge([\$order->id]);\n        }\n        return response()->json(\$ids);");

        self::assertSame([], $this->accumulations($summary), 'merge() into another variable leaves $ids as it was');
    }

    public function testWhatIsPassedToPushIsWalkedWhoeverTheReceiverIs(): void
    {
        $summary = $this->summary("        collect()->push(Order::first());\n        return response()->json(true);");

        self::assertSame([], $summary->gaps);
        self::assertNotEmpty(array_filter($summary->events, static fn (CostEvent $e): bool => $e->query !== null), 'the read inside push() is found');
    }

    /** @return list<array{int|null, string|null, int|null}> */
    private function accumulations(Summary $s): array
    {
        return array_values(array_map(
            static fn (CostEvent $e): array => [$e->memory?->ownDegree, $e->memory?->ownOrigin?->table, $e->memory?->retainFrom],
            array_filter($s->events, static fn (CostEvent $e): bool => $e->kind === EventKind::Accumulate),
        ));
    }

    public function testASteamMadeIntoAnArrayHoldsEveryRow(): void
    {
        $summary = $this->summary($this->bodyOf('c-stream-materialize'));

        $payload = array_values(array_filter($summary->events, static fn (CostEvent $e): bool => $e->kind === EventKind::Payload));
        self::assertCount(1, $payload);
        self::assertSame('orders', $payload[0]->memory?->ownOrigin?->table);
    }

    public function testAMethodTheCollectionDoesNotHaveIsUnknownButWhatWasFoundStays(): void
    {
        $summary = $this->summary('        return response()->json(Order::get()->customMacro());');

        self::assertSame([GapCode::UNKNOWN_COLLECTION_METHOD], array_map(static fn ($g) => $g->code, $summary->gaps));
        self::assertNotEmpty($summary->events, 'the get() before the macro is still reported');
        self::assertSame(1, CandidateComparison::degrees($summary)['memory']);
    }

    public function testTheBuilderWhereAndTheCollectionWhereAreNotMixedUp(): void
    {
        $builder = $this->summary("        return response()->json(Order::where('status', 'open')->get());");
        $collection = $this->summary("        return response()->json(Order::get()->where('status', 'open'));");

        self::assertSame([], $builder->gaps);
        self::assertSame([], $collection->gaps);
        self::assertSame(['query' => 0, 'memory' => 1], CandidateComparison::degrees($builder));
        self::assertSame(['query' => 0, 'memory' => 1], CandidateComparison::degrees($collection));
    }

    public function testPluckKeepsWhichTableTheLoopIsOver(): void
    {
        $summary = $this->summary(<<<'PHP'
                $ids = Order::get()->pluck('id');
                foreach ($ids as $id) {
                    Order::find($id);
                }
        PHP);

        $find = array_values(array_filter($summary->events, static fn (CostEvent $e): bool => $e->frames !== []));
        self::assertCount(1, $find);
        self::assertSame('orders', $find[0]->frames[0]->origin->table, 'one id per order: the loop is as long as orders');
        self::assertSame([], $summary->gaps);
    }

    public function testAnArrayChunkedByHandIsStillLinearInQueries(): void
    {
        $summary = $this->summary(<<<'PHP'
                $ids = Order::get()->pluck('id')->all();
                foreach (array_chunk($ids, 1000) as $part) {
                    Order::whereIn('id', $part)->get();
                }
        PHP);

        self::assertSame(1, CandidateComparison::degrees($summary)['query']);
        self::assertSame([], $summary->gaps);
    }

    private function bodyOf(string $case): string
    {
        return self::cases()[$case][0];
    }
}
