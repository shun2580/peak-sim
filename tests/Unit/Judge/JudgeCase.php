<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Judge;

use PeakSim\Analysis\Type\PlainT;
use PeakSim\Input\RouteEntry;
use PeakSim\Judge\Judge;
use PeakSim\Model\Assumption;
use PeakSim\Model\AssumptionCode;
use PeakSim\Model\Axis;
use PeakSim\Model\AxisVerdict;
use PeakSim\Model\CostEvent;
use PeakSim\Model\EventKind;
use PeakSim\Model\Frame;
use PeakSim\Model\Gap;
use PeakSim\Model\GapCode;
use PeakSim\Model\MemoryCause;
use PeakSim\Model\MemoryPart;
use PeakSim\Model\Origin;
use PeakSim\Model\QueryPart;
use PeakSim\Model\RouteResult;
use PeakSim\Model\ScenarioTag;
use PeakSim\Model\Site;
use PeakSim\Model\Summary;
use PHPUnit\Framework\TestCase;

/** Hand-built events, gaps and assumptions. The judge never sees an AST, so none is needed here. */
abstract class JudgeCase extends TestCase
{
    private const FILE = 'app/Http/Controllers/OrderController.php';

    protected int $nextSeq = 0;

    protected function setUp(): void
    {
        $this->nextSeq = 0;
    }

    protected static function site(int $line = 10, string $file = self::FILE): Site
    {
        return new Site($file, $line);
    }

    protected static function frame(string $table, ?string $per = null, bool $streamed = false, int $line = 20): Frame
    {
        return new Frame(new Origin($table, $per), $streamed, self::site($line));
    }

    protected static function query(bool $unbounded = false, ?string $ownTable = null, int $statements = 1, bool $conditional = false, ?int $chunk = null): QueryPart
    {
        return new QueryPart($unbounded, $ownTable === null ? null : new Origin($ownTable), $statements, $conditional, $chunk);
    }

    protected static function memory(int $degree, ?string $ownTable = null, MemoryCause $cause = MemoryCause::Materialize, ?int $retainFrom = null, ?string $per = null): MemoryPart
    {
        return new MemoryPart($degree, $ownTable === null ? null : new Origin($ownTable, $per), $cause, $retainFrom);
    }

    /**
     * @param array{kind?: EventKind, line?: int, frames?: list<Frame>, query?: ?QueryPart, memory?: ?MemoryPart, table?: ?string, scenario?: list<ScenarioTag>, seq?: int} $o
     */
    protected function event(array $o = []): CostEvent
    {
        return new CostEvent(
            $o['kind'] ?? EventKind::Select,
            self::site($o['line'] ?? 40 + $this->nextSeq),
            $o['frames'] ?? [],
            array_key_exists('query', $o) ? $o['query'] : self::query(),
            $o['memory'] ?? null,
            array_key_exists('table', $o) ? $o['table'] : 'orders',
            $o['scenario'] ?? [],
            $o['seq'] ?? $this->nextSeq++,
        );
    }

    /**
     * @param list<Axis>|null $affects
     * @param list<ScenarioTag> $scenario
     */
    protected static function gap(?array $affects = null, int $line = 30, array $scenario = [], int $loopDepth = 0, GapCode $code = GapCode::UNRESOLVED_CALL): Gap
    {
        return new Gap($code, self::site($line), 'cannot follow', $affects ?? [Axis::Query, Axis::Memory], $loopDepth, $scenario);
    }

    /**
     * @param list<Axis> $affects
     * @param list<ScenarioTag> $scenario
     * @param array<array-key, mixed>|null $candidates
     */
    protected static function assumption(array $affects, AssumptionCode $code = AssumptionCode::RELATION_INFERRED, array $scenario = [], int $line = 50, ?array $candidates = null, string $message = 'assumed'): Assumption
    {
        return new Assumption($code, self::site($line), $message, $affects, $scenario, $candidates);
    }

    /**
     * @param list<CostEvent> $events
     * @param list<Gap> $gaps
     * @param list<Assumption> $assumptions
     * @param list<Site> $trace
     */
    protected static function summary(array $events = [], array $gaps = [], array $assumptions = [], array $trace = [], bool $truncated = false): Summary
    {
        return new Summary($events, $gaps, $assumptions, $trace, new PlainT(), $truncated);
    }

    protected static function route(string $uri = '/orders', string $action = 'App\Http\Controllers\OrderController@index', array $methods = ['GET', 'HEAD']): RouteEntry
    {
        return new RouteEntry($uri, $methods, null, $action, [], null, null);
    }

    protected static function judge(Summary $summary, ?RouteEntry $route = null): RouteResult
    {
        return (new Judge())->judge($route ?? self::route(), $summary);
    }

    /** @return array{0: string, 1: int, 2: ?string, 3: ?string} growth, degree, confidence, lower bound */
    protected static function shape(AxisVerdict $v): array
    {
        return [$v->growth->value, $v->degree, $v->confidence?->value, $v->lowerBound?->value];
    }

    protected static function miss(int $cache = 1): ScenarioTag
    {
        return new ScenarioTag($cache, ScenarioTag::MISS);
    }

    protected static function hit(int $cache = 1): ScenarioTag
    {
        return new ScenarioTag($cache, ScenarioTag::HIT);
    }
}
