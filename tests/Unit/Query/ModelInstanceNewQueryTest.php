<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Query;

use PeakSim\Analysis\Contracts\CallSite;
use PeakSim\Analysis\Contracts\HandlerRegistry;
use PeakSim\Analysis\Engine\CandidateComparison;
use PeakSim\Analysis\Engine\Engine;
use PeakSim\Analysis\Engine\EngineOptions;
use PeakSim\Analysis\Handlers\Query\RelationOps;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\UnknownT;
use PeakSim\Binding\BindingResult;
use PeakSim\Binding\BindingTable;
use PeakSim\Model\Gap;
use PeakSim\Model\Summary;

/**
 * `$order->newQuery()` on a model row is a fresh query builder for that model, as `Order::query()` is. Only that
 * one method is known (D28: Eloquent's own `Model::newQuery()` returns an Eloquent builder). Anything else the
 * model does not define stays a gap, and so does a receiver whose type is not known (D21).
 */
final class ModelInstanceNewQueryTest extends QueryCase
{
    private const CONTROLLER = 'App\\Http\\Controllers\\NewQueryController';

    private function summary(string $method): Summary
    {
        [$source, $models] = $this->catalog();
        $registry = new HandlerRegistry();
        foreach ($this->handlers() as $i => $handler) {
            $registry->register($handler, 10 + $i);
        }
        $none = new class implements BindingTable {
            public function resolve(string $abstract, ?string $consumer = null): BindingResult
            {
                return BindingResult::none();
            }
        };
        $engine = new Engine($source, $models, $none, $registry, new EngineOptions(auditUnknown: true));

        return $engine->analyzeAction(self::CONTROLLER, $method);
    }

    /** @return list<string> */
    private function gapCodesOf(Summary $summary): array
    {
        return array_map(static fn (Gap $g): string => $g->code->value, $summary->gaps);
    }

    public function testNewQueryOnARowIsABuilderAndTheQueryCountsPerRow(): void
    {
        $summary = $this->summary('newQueryInLoop');

        $this->assertSame([], $this->gapCodesOf($summary));
        $this->assertSame(['query' => 1, 'memory' => 1], CandidateComparison::degrees($summary));
    }

    public function testNewQueryOnARowThenGetReadsTheTable(): void
    {
        $summary = $this->summary('newQueryThenGet');

        $this->assertSame([], $this->gapCodesOf($summary));
        $this->assertSame(['query' => 0, 'memory' => 1], CandidateComparison::degrees($summary));
    }

    public function testTheHandlerAnswersWithABuilderForTheRowsModel(): void
    {
        $w = $this->walker(['order' => new ModelT(self::M . 'Order')]);
        $t = $w->run('$order->newQuery()->where("a", 1)->get()');

        $this->assertSame(['select orders q=1 m=1:orders:materialize'], $this->events($w));
        $this->assertNotInstanceOf(UnknownT::class, $t);
        $this->assertSame([], $this->gapCodes($w));
        $this->assertSame([], $w->unhandled);
    }

    public function testAnUnknownMethodOnTheRowIsStillAGap(): void
    {
        $summary = $this->summary('unknownMethodOnTheInstance');

        $this->assertSame(['UNKNOWN_BUILDER_METHOD'], $this->gapCodesOf($summary));
        $this->assertStringContainsString('mysteryMethod', $summary->gaps[0]->message);
    }

    public function testAnUnknownMethodAfterNewQueryIsTheBuildersGapNotNewQuerys(): void
    {
        $summary = $this->summary('unknownMethodOnTheNewQuery');

        $this->assertSame(['UNKNOWN_BUILDER_METHOD'], $this->gapCodesOf($summary));
        $this->assertStringContainsString('mysteryMethod', $summary->gaps[0]->message);
        $this->assertStringNotContainsString('newQuery', $summary->gaps[0]->message);
    }

    public function testNewQueryOnAReceiverOfUnknownTypeStaysAGap(): void
    {
        $summary = $this->summary('newQueryOnAnUnknownReceiver');

        $this->assertSame(['VENDOR_CALL_NOT_MODELLED'], $this->gapCodesOf($summary));
    }

    public function testAModelThatDefinesNewQueryItselfIsLeftToTheEngine(): void
    {
        $w = $this->walker();
        $node = $w->expression('$order->newQuery()');
        $ops = new RelationOps();

        $own = new CallSite($node, new ModelT(self::M . 'OwnQueryOrder'), null, 'newQuery', []);
        $plain = new CallSite($node, new ModelT(self::M . 'Order'), null, 'newQuery', []);

        $this->assertFalse($ops->handles($own, $w->ctx));
        $this->assertTrue($ops->handles($plain, $w->ctx));
    }
}
