<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Query;

use PeakSim\Analysis\Handlers\Query\BuilderOps;
use PeakSim\Analysis\Handlers\Query\ModelStatic;
use PeakSim\Analysis\Handlers\Query\RelationOps;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Input\ArtisanSource;
use PeakSim\Input\ModelInfo;
use PeakSim\Input\RelationInfo;
use PeakSim\Model\Cardinality;
use PeakSim\Model\CostEvent;
use PeakSim\Model\Delivery;
use PeakSim\Model\Origin;
use PeakSim\Source\AppModelCatalog;
use PeakSim\Source\AppSourceIndex;
use PHPUnit\Framework\TestCase;

/** Shared set-up: the unit-test app, an in-memory model:show export, and the three Query handlers. */
abstract class QueryCase extends TestCase
{
    protected const M = 'App\\Models\\';

    /** @var list<string> */
    private const MANY = ['HasMany', 'BelongsToMany', 'MorphMany', 'MorphToMany', 'HasManyThrough'];

    /**
     * @param array<string, list<mixed>> $observers class => observers
     * @param array<string, string> $databases class => database (default mysql)
     */
    protected function walker(array $vars = [], array $observers = [], array $databases = []): QueryWalker
    {
        [$source, $models] = $this->catalog($observers, $databases);

        return new QueryWalker($source, $models, $this->handlers(), $vars);
    }

    /** @return list<\PeakSim\Analysis\Contracts\CallHandler> */
    protected function handlers(): array
    {
        $ops = new BuilderOps();

        return [new ModelStatic($ops), $ops, new RelationOps($ops)];
    }

    /**
     * @param array<string, list<mixed>> $observers
     * @param array<string, string> $databases
     * @return array{0: AppSourceIndex, 1: AppModelCatalog}
     */
    protected function catalog(array $observers = [], array $databases = []): array
    {
        $source = new AppSourceIndex(__DIR__ . '/App');

        return [$source, new AppModelCatalog($source, $this->export($observers, $databases))];
    }

    /**
     * @param array<string, list<mixed>> $observers
     * @param array<string, string> $databases
     */
    private function export(array $observers, array $databases): ArtisanSource
    {
        $rel = static fn (string $name, string $type, string $related): RelationInfo
            => new RelationInfo($name, $type, self::M . $related, in_array($type, self::MANY, true));
        $order = [
            'customer' => $rel('customer', 'BelongsTo', 'Customer'),
            'items' => $rel('items', 'HasMany', 'Item'),
            'tags' => $rel('tags', 'BelongsToMany', 'Product'),
        ];
        $models = [
            'Order' => ['orders', $order],
            'WithOrder' => ['orders', $order],
            'TwoWithOrder' => ['orders', $order],
            'ObservedOrder' => ['orders', $order],
            'DynamicWithOrder' => ['orders', $order],
            'Customer' => ['customers', ['orders' => $rel('orders', 'HasMany', 'Order')]],
            'Item' => ['items', [
                'order' => $rel('order', 'BelongsTo', 'Order'),
                'withOrder' => $rel('withOrder', 'BelongsTo', 'WithOrder'),
                'product' => $rel('product', 'BelongsTo', 'Product'),
            ]],
            'Product' => ['products', ['items' => $rel('items', 'HasMany', 'Item')]],
            'CycleOrder' => ['orders', ['cycleCustomer' => $rel('cycleCustomer', 'BelongsTo', 'CycleCustomer')]],
            'CycleCustomer' => ['customers', ['cycleOrders' => $rel('cycleOrders', 'HasMany', 'CycleOrder')]],
            'ItemWithProduct' => ['items', ['product' => $rel('product', 'BelongsTo', 'Product')]],
        ];
        // InferredOrder and NoInfo are not exported: the first inherits Order's export (inferred), the second has none.
        $infos = [];
        foreach ($models as $short => [$table, $relations]) {
            $class = self::M . $short;
            $infos[$class] = new ModelInfo($class, $table, $databases[$class] ?? 'mysql', $relations, [], [], $observers[$class] ?? []);
        }
        foreach ($observers as $class => $list) {
            $infos[$class] = new ModelInfo($class, $infos[$class]->table, $infos[$class]->database, $infos[$class]->relations, [], [], $list);
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

    /** @return list<string> */
    protected function events(QueryWalker $w): array
    {
        return array_map($this->describe(...), $w->fake->events());
    }

    /** One line per event: kind, table, query part, memory part, frames. */
    protected function describe(CostEvent $e): string
    {
        $parts = [$e->kind->value, $e->table ?? '-'];
        if ($e->query !== null) {
            $q = $e->query;
            $parts[] = 'q=' . $q->statements . ($q->ownUnbounded ? 'U' : '') . ($q->conditional ? 'c' : '')
                . ($q->chunkSize !== null ? '/' . $q->chunkSize : '') . ($q->ownOrigin !== null ? '@' . $q->ownOrigin->fingerprint() : '');
        }
        if ($e->memory !== null) {
            $m = $e->memory;
            $parts[] = 'm=' . $m->ownDegree . ':' . ($m->ownOrigin?->fingerprint() ?? '-') . ':' . $m->cause->value;
        }
        if ($e->frames !== []) {
            $parts[] = 'f=' . implode('>', array_map(static fn ($f): string => $f->origin->fingerprint() . ($f->streamed ? '~' : ''), $e->frames));
        }

        return implode(' ', $parts);
    }

    /** @return list<string> */
    protected function gapCodes(QueryWalker $w): array
    {
        return array_map(static fn ($g): string => $g->code->value, $w->fake->gaps());
    }

    /** @return list<string> */
    protected function assumptionCodes(QueryWalker $w): array
    {
        return array_map(static fn ($a): string => $a->code->value, $w->fake->assumptions());
    }

    /** Every test ends here: no UnknownT without a gap (I1), and no call left without a handler. */
    protected function assertClean(QueryWalker $w): void
    {
        $this->assertSame([], $w->ctx->violations(), 'UnknownT made without a gap');
        $this->assertSame([], $w->unhandled, 'calls that no Query handler took');
    }

    protected function rows(string $class, ?string $table, bool $window = false, Delivery $delivery = Delivery::Materialized, array $eager = []): ListT
    {
        return new ListT(new ModelT(self::M . $class, $eager, new Origin($table)), Cardinality::table($table), $delivery, $window);
    }

    protected function assertListOfTable(Type $t, string $class, ?string $table, Delivery $delivery = Delivery::Materialized, ?string $per = null): void
    {
        $this->assertInstanceOf(ListT::class, $t);
        $this->assertSame('table', $t->card->kind->value);
        $this->assertSame($table, $t->card->origin?->table);
        $this->assertSame($per, $t->card->origin?->perTable);
        $this->assertSame($delivery, $t->delivery);
        $this->assertInstanceOf(ModelT::class, $t->elem);
        $this->assertSame(self::M . $class, $t->elem->class);
    }
}
