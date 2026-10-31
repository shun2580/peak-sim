<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Known;

use PeakSim\Analysis\Contracts\CallHandler;
use PeakSim\Analysis\Handlers\Cache\CacheOps;
use PeakSim\Analysis\Handlers\Known\KnownCalls;
use PeakSim\Analysis\Handlers\Known\KnownTable;
use PeakSim\Analysis\Type\ObjectT;
use PeakSim\Model\Assumption;
use PeakSim\Model\Axis;
use PeakSim\Model\CostEvent;
use PeakSim\Model\Gap;
use PeakSim\Model\ScenarioTag;
use PHPUnit\Framework\TestCase;

/** Shared set-up for the allow-list and cache handler tests. */
abstract class KnownCase extends TestCase
{
    public const RESPONSE_FACTORY = 'Illuminate\\Contracts\\Routing\\ResponseFactory';
    public const REQUEST = 'Illuminate\\Http\\Request';
    public const CACHE_MANAGER = 'Illuminate\\Cache\\CacheManager';
    public const CACHE_REPOSITORY = 'Illuminate\\Contracts\\Cache\\Repository';

    /**
     * The allow-list alone, on top of the reads stub (the way the real registry puts it: last).
     *
     * @param array<string, \PeakSim\Analysis\Type\Type> $vars
     * @param array<string, list<string>> $bound
     */
    protected function known(array $vars = [], array $bound = [], ?KnownTable $table = null): KnownWalker
    {
        return new KnownWalker([new StubReads(), new KnownCalls($table)], $vars, $bound);
    }

    /**
     * The cache handler in front of the reads stub and the allow-list.
     *
     * @param array<string, \PeakSim\Analysis\Type\Type> $vars
     */
    protected function cache(array $vars = []): KnownWalker
    {
        return new KnownWalker([new CacheOps(), new StubReads(), new KnownCalls()], $vars + [
            'cache' => new ObjectT(self::CACHE_MANAGER),
            'repo' => new ObjectT(self::CACHE_REPOSITORY),
        ]);
    }

    /** @param list<CallHandler> $handlers */
    protected function with(array $handlers): KnownWalker
    {
        return new KnownWalker($handlers);
    }

    /** @return list<string> */
    protected function gapCodes(KnownWalker $w): array
    {
        return array_map(static fn (Gap $g): string => $g->code->value, $w->fake->gaps());
    }

    /** @return list<string> */
    protected function gapMessages(KnownWalker $w): array
    {
        return array_map(static fn (Gap $g): string => $g->message, $w->fake->gaps());
    }

    /** @return list<string> */
    protected function assumptionCodes(KnownWalker $w): array
    {
        return array_map(static fn (Assumption $a): string => $a->code->value, $w->fake->assumptions());
    }

    /** One line per event: kind, table, parts, loops, and which side of a cache it is on. */
    protected function describe(CostEvent $e): string
    {
        $parts = [$e->kind->value, $e->table ?? '-'];
        if ($e->query !== null) {
            $parts[] = 'q=' . $e->query->statements;
        }
        if ($e->memory !== null) {
            $parts[] = 'm=' . $e->memory->ownDegree . ':' . ($e->memory->ownOrigin?->fingerprint() ?? '-') . ':' . $e->memory->cause->value;
        }
        if ($e->frames !== []) {
            $parts[] = 'f=' . implode('>', array_map(static fn ($f): string => $f->origin->fingerprint(), $e->frames));
        }
        if ($e->scenario !== []) {
            $parts[] = 's=' . implode(',', array_map(static fn (ScenarioTag $t): string => $t->side, $e->scenario));
        }

        return implode(' ', $parts);
    }

    /** @return list<string> */
    protected function events(KnownWalker $w): array
    {
        return array_map($this->describe(...), $w->fake->events());
    }

    /** @param list<ScenarioTag> $tags @return list<string> */
    protected function sides(array $tags): array
    {
        return array_map(static fn (ScenarioTag $t): string => $t->side, $tags);
    }

    /** @return list<Axis> */
    protected function memoryOnly(): array
    {
        return [Axis::Memory];
    }

    /** Every test ends here: no UnknownT without a gap (I1). */
    protected function assertClean(KnownWalker $w): void
    {
        $this->assertSame([], $w->ctx->violations(), 'UnknownT made without a gap');
    }
}
