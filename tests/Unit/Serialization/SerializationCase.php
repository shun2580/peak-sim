<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Serialization;

use PeakSim\Analysis\Contracts\HandlerRegistry;
use PeakSim\Analysis\Engine\CandidateComparison;
use PeakSim\Analysis\Engine\Engine;
use PeakSim\Analysis\Engine\EngineOptions;
use PeakSim\Analysis\Handlers\Query\BuilderOps;
use PeakSim\Analysis\Handlers\Query\ModelStatic;
use PeakSim\Analysis\Handlers\Query\RelationOps;
use PeakSim\Analysis\Serialization\Serialization;
use PeakSim\Binding\BindingResult;
use PeakSim\Binding\BindingTable;
use PeakSim\Input\ArtisanSource;
use PeakSim\Input\ModelInfo;
use PeakSim\Input\RelationInfo;
use PeakSim\Model\CostEvent;
use PeakSim\Model\EventKind;
use PeakSim\Model\Gap;
use PeakSim\Model\Summary;
use PeakSim\Source\AppModelCatalog;
use PeakSim\Source\AppSourceIndex;
use PeakSim\Tests\Unit\Serialization\Support\StubKnown;
use PHPUnit\Framework\TestCase;

/**
 * The serialisation package behind the real engine, with the real Query handlers and the test app in ./App.
 * Each case is one action of App\Http\Controllers\SerializationController; the answer is read from the summary
 * the engine gives for it (degrees by the engine's own formula, the events with their loops, the gaps).
 */
abstract class SerializationCase extends TestCase
{
    protected const M = 'App\\Models\\';
    protected const CONTROLLER = 'App\\Http\\Controllers\\SerializationController';

    protected ?Engine $engine = null;

    protected function summary(string $action): Summary
    {
        $source = new AppSourceIndex(__DIR__ . '/App');
        $models = new AppModelCatalog($source, $this->export());
        $kit = Serialization::create();
        $ops = new BuilderOps();
        $registry = new HandlerRegistry();
        $priority = 10;
        foreach ([...$kit->handlers, new ModelStatic($ops), $ops, new RelationOps($ops), new StubKnown()] as $handler) {
            $registry->register($handler, $priority++);
        }
        $this->engine = new Engine($source, $models, $this->noBindings(), $registry, new EngineOptions(auditUnknown: true), $kit->hook);

        return $this->engine->analyzeAction(self::CONTROLLER, $action);
    }

    /** Every case ends here: no UnknownT was made without a gap (invariant I1). */
    protected function assertNoUnknownWithoutGap(): void
    {
        self::assertNotNull($this->engine);
        self::assertSame([], $this->engine->violations(), 'UnknownT made without a gap');
    }

    /** @return array{query: int, memory: int} */
    protected function degrees(Summary $s): array
    {
        return CandidateComparison::degrees($s);
    }

    /** @return list<string> */
    protected function gapCodes(Summary $s): array
    {
        return array_map(static fn (Gap $g): string => $g->code->value, $s->gaps);
    }

    /** @return list<string> */
    protected function gapDescriptions(Summary $s): array
    {
        return array_map(static fn (Gap $g): string => $g->code->value . ': ' . $g->message, $s->gaps);
    }

    /** @return list<CostEvent> */
    protected function events(Summary $s, ?EventKind $kind = null): array
    {
        return array_values(array_filter($s->events, static fn (CostEvent $e): bool => $kind === null || $e->kind === $kind));
    }

    /** The tables the loops around an event come from, outermost first. @return list<?string> */
    protected function loops(CostEvent $e): array
    {
        return array_map(static fn ($f): ?string => $f->origin->table, $e->frames);
    }

    /** One line per event: kind, table, loops. @return list<string> */
    protected function trace(Summary $s): array
    {
        return array_map(
            fn (CostEvent $e): string => $e->kind->value . ' ' . ($e->table ?? '-') . ($e->frames === [] ? '' : ' in ' . implode('>', array_map(static fn ($t): string => $t ?? '?', $this->loops($e)))),
            $s->events,
        );
    }

    private function noBindings(): BindingTable
    {
        return new class implements BindingTable {
            public function resolve(string $abstract, ?string $consumer = null): BindingResult
            {
                return BindingResult::none();
            }
        };
    }

    private function export(): ArtisanSource
    {
        $rel = static fn (string $name, string $type, string $related): RelationInfo
            => new RelationInfo($name, $type, self::M . $related, in_array($type, ['HasMany', 'BelongsToMany'], true));
        $order = static fn (string $customer = 'Customer', string $items = 'Item'): array => [
            'customer' => $rel('customer', 'BelongsTo', $customer),
            'items' => $rel('items', 'HasMany', $items),
        ];
        $item = [
            'order' => $rel('order', 'BelongsTo', 'Order'),
            'product' => $rel('product', 'BelongsTo', 'Product'),
        ];
        $models = [
            'Customer' => ['customers', ['orders' => $rel('orders', 'HasMany', 'Order')]],
            'AppendedCustomer' => ['customers', ['orders' => $rel('orders', 'HasMany', 'Order')]],
            'Order' => ['orders', $order()],
            'AppendedOrder' => ['orders', $order()],
            'MadeAppendedOrder' => ['orders', $order()],
            'OldAppendedOrder' => ['orders', $order()],
            'AccessorOrder' => ['orders', $order()],
            'DynamicAppendsOrder' => ['orders', $order()],
            'GhostAppendsOrder' => ['orders', $order()],
            'NestedOrder' => ['orders', $order('AppendedCustomer')],
            'ItemsOrder' => ['orders', $order('Customer', 'AppendedItem')],
            'Item' => ['items', $item],
            'AppendedItem' => ['items', $item],
            'Product' => ['products', []],
            'Category' => ['categories', ['children' => $rel('children', 'HasMany', 'Category')]],
        ];
        $infos = [];
        foreach ($models as $short => [$table, $relations]) {
            $infos[self::M . $short] = new ModelInfo(self::M . $short, $table, 'mysql', $relations, [], [], []);
        }

        return new class ($infos) implements ArtisanSource {
            /** @param array<string, ModelInfo> $infos */
            public function __construct(private readonly array $infos)
            {
            }

            public function routes(): array
            {
                return [];
            }

            public function modelInfo(string $class): ?ModelInfo
            {
                return $this->infos[$class] ?? null;
            }
        };
    }
}
