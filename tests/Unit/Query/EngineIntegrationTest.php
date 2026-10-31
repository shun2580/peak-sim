<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Query;

use PeakSim\Analysis\Contracts\HandlerRegistry;
use PeakSim\Analysis\Engine\CandidateComparison;
use PeakSim\Analysis\Engine\Engine;
use PeakSim\Analysis\Engine\EngineOptions;
use PeakSim\Model\Gap;
use PeakSim\Model\Summary;
use PeakSim\Binding\BindingResult;
use PeakSim\Binding\BindingTable;

/**
 * The three Query handlers behind the real engine: the CallSites it builds (property fetch, new, static call), the
 * closures it hands over, and the loops it puts around the events. Degrees come from the engine's own formula.
 */
final class EngineIntegrationTest extends QueryCase
{
    private const CONTROLLER = 'App\\Http\\Controllers\\QueryController';

    private ?Engine $engine = null;

    private function summary(string $method): Summary
    {
        [$source, $models] = $this->catalog();
        $registry = new HandlerRegistry();
        foreach ($this->handlers() as $i => $handler) {
            $registry->register($handler, 10 + $i);
        }
        $this->engine = new Engine($source, $models, $this->noBindings(), $registry, new EngineOptions(auditUnknown: true));

        return $this->engine->analyzeAction(self::CONTROLLER, $method);
    }

    /** The controllers under test inject nothing, so there is nothing to bind. */
    private function noBindings(): BindingTable
    {
        return new class implements BindingTable {
            public function resolve(string $abstract, ?string $consumer = null): BindingResult
            {
                return BindingResult::none();
            }
        };
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    public static function routes(): array
    {
        return [
            'lazy load of a to-one in a loop' => ['nPlusOne', 1, 1],
            'eager loaded relation costs no query in the loop' => ['eagerIsFine', 0, 1],
            'to-many lazy load, then a lazy load inside it' => ['nested', 2, 2],
            'relation builder count in the loop' => ['countInLoop', 1, 1],
            'chunk reads in windows: queries grow, memory does not' => ['chunked', 1, 0],
            'a lookup per row inside a chunk is still one level' => ['chunkedWithLookup', 1, 0],
            'lazy streams: queries grow, memory does not' => ['lazily', 1, 0],
            'cursor on mysql holds every row' => ['cursored', 0, 1],
            'paginate is bounded' => ['paged', 0, 0],
            'pluck then insert keeps the plucked size' => ['bulk', 0, 1],
            'model with $with adds one query, not one per row' => ['modelWith', 0, 1],
            'save in a loop' => ['saveInLoop', 1, 1],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('routes')]
    public function testDegreesMatchWhatTheDesignExpects(string $method, int $query, int $memory): void
    {
        $summary = $this->summary($method);

        $this->assertSame(['query' => $query, 'memory' => $memory], CandidateComparison::degrees($summary), $method);
        $this->assertSame([], array_map(static fn (Gap $g): string => $g->code->value . ' ' . $g->message, $summary->gaps), $method);
        $this->assertSame([], $this->engine?->violations());
        $this->assertGreaterThan(0, count($summary->events), $method);
    }

    public function testPluckThenInsertCarriesTheOriginOfTheNToThePayload(): void
    {
        $summary = $this->summary('bulk');
        $payload = array_values(array_filter($summary->events, static fn ($e): bool => $e->memory !== null && $e->memory->cause->value === 'payload'));

        $this->assertCount(1, $payload);
        $this->assertSame('customers', $payload[0]->memory->ownOrigin?->table);
    }

    public function testTheToManyLoopHasAnItemsFrameWithItsOrdersOrigin(): void
    {
        $summary = $this->summary('nested');
        $deepest = array_values(array_filter($summary->events, static fn ($e): bool => count($e->frames) === 2));

        $this->assertNotEmpty($deepest);
        $this->assertSame(['orders', 'items'], array_map(static fn ($f): ?string => $f->origin->table, $deepest[0]->frames));
        $this->assertSame('orders', $deepest[0]->frames[1]->origin->perTable);
    }
}
