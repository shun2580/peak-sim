<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Model;

use PeakSim\Analysis\Contracts\CallHandler;
use PeakSim\Analysis\Contracts\CallSite;
use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Contracts\HandlerRegistry;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\ShapeT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Analysis\Type\UnionT;
use PeakSim\Analysis\Type\UnknownT;
use PeakSim\Model\Axis;
use PeakSim\Model\Cardinality;
use PeakSim\Model\CostEvent;
use PeakSim\Model\EventKind;
use PeakSim\Model\Frame;
use PeakSim\Model\GapCode;
use PeakSim\Model\Origin;
use PeakSim\Model\QueryPart;
use PeakSim\Model\ScenarioTag;
use PeakSim\Model\Site;
use PeakSim\Tests\Support\FakeEvalContext;
use PeakSim\Tests\Support\RecordingContext;
use PhpParser\Node;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

/** The test doubles shared by every handler's unit tests must themselves behave. */
final class DoublesTest extends TestCase
{
    public function testFakeContextAddsTheCurrentFramesAndScenarioToEmittedEvents(): void
    {
        $ctx = new FakeEvalContext();
        $frame = new Frame(new Origin('orders'), false, new Site('app/A.php', 3));
        $tag = new ScenarioTag(9, ScenarioTag::MISS);

        $ctx->withFrame($frame, function () use ($ctx, $tag): void {
            $ctx->withScenario($tag, function () use ($ctx): void {
                $ctx->emit($this->select());
            });
            $ctx->emit($this->select());
        });
        $ctx->emit($this->select());

        $events = $ctx->events();
        self::assertCount(3, $events);
        self::assertSame([$frame], $events[0]->frames);
        self::assertSame([$tag], $events[0]->scenario);
        self::assertSame([$frame], $events[1]->frames);
        self::assertSame([], $events[1]->scenario);
        self::assertSame([], $events[2]->frames);
    }

    public function testFakeContextPopsFramesEvenWhenTheBodyThrows(): void
    {
        $ctx = new FakeEvalContext();
        $frame = new Frame(new Origin('orders'), false, new Site('app/A.php', 3));

        try {
            $ctx->withFrame($frame, static function (): void {
                throw new \RuntimeException('boom');
            });
            self::fail('the exception must pass through');
        } catch (\RuntimeException) {
            self::assertSame([], $ctx->frames());
        }
    }

    public function testFakeContextGapRecordsLoopDepthAndScenarioAndReturnsAnUnknown(): void
    {
        $ctx = new FakeEvalContext();
        $node = $this->node();
        $frame = new Frame(new Origin('orders'), false, new Site('app/A.php', 3));

        $unknown = $ctx->withFrame($frame, fn (): UnknownT => $ctx->gap(GapCode::UNRESOLVED_CALL, $node, 'cannot follow', [Axis::Query]));

        self::assertInstanceOf(UnknownT::class, $unknown);
        self::assertCount(1, $ctx->gaps());
        $gap = $ctx->gaps()[0];
        self::assertSame(GapCode::UNRESOLVED_CALL, $gap->code);
        self::assertSame([Axis::Query], $gap->affects);
        self::assertSame(1, $gap->loopDepth);
        self::assertSame('app/Fake.php', $gap->site->file);
    }

    public function testFakeContextGapAffectsBothAxesByDefault(): void
    {
        $ctx = new FakeEvalContext();
        $ctx->gap(GapCode::RECURSION, $this->node(), 'loop');
        $ctx->assume(\PeakSim\Model\AssumptionCode::BRANCH_WORST_CASE, $this->node(), 'worst', null, ['a', 'b']);

        self::assertSame([Axis::Query, Axis::Memory], $ctx->gaps()[0]->affects);
        self::assertSame([Axis::Query, Axis::Memory], $ctx->assumptions()[0]->affects);
        self::assertSame(['a', 'b'], $ctx->assumptions()[0]->candidates);
    }

    public function testFakeContextHandsOutIncreasingSeqNumbers(): void
    {
        $ctx = new FakeEvalContext();

        self::assertSame([0, 1, 2], [$ctx->seq(), $ctx->seq(), $ctx->seq()]);
    }

    public function testFakeContextRefusesServicesItWasNotGiven(): void
    {
        $ctx = new FakeEvalContext();

        $this->expectException(\LogicException::class);
        $ctx->models();
    }

    public function testRecordingContextFlagsAnUnknownMadeWithoutAGap(): void
    {
        $ctx = new RecordingContext(new FakeEvalContext());

        $ctx->audit(new UnknownT(), 'a handler that cheats');

        self::assertCount(1, $ctx->violations());
        self::assertStringContainsString('a handler that cheats', $ctx->violations()[0]);
    }

    public function testRecordingContextAcceptsAnUnknownThatCameFromGap(): void
    {
        $ctx = new RecordingContext(new FakeEvalContext());

        $unknown = $ctx->gap(GapCode::UNRESOLVED_CALL, $this->node(), 'cannot follow');
        $ctx->audit($unknown, 'honest');
        $ctx->audit($unknown, 'the same value used again later');

        self::assertSame([], $ctx->violations());
        self::assertSame(1, $ctx->gapCalls());
    }

    public function testRecordingContextLooksInsideContainers(): void
    {
        $ctx = new RecordingContext(new FakeEvalContext());
        $cheat = static fn (): UnknownT => new UnknownT();
        $list = new ListT(new PlainT(), Cardinality::bounded());

        $ctx->audit($list->withElem($cheat()), 'list element');
        $ctx->audit(new ShapeT(['k' => $cheat()]), 'shape entry');
        $ctx->audit(new UnionT([new PlainT(), $cheat()]), 'union member');
        $ctx->audit(new ModelT('App\Order'), 'a model has no unknown');

        self::assertCount(3, $ctx->violations());
    }

    public function testRecordingContextAuditsWhatEvaluateAndHandlersReturn(): void
    {
        $inner = new FakeEvalContext(evaluator: static fn (Node\Expr $e): Type => new UnknownT());
        $ctx = new RecordingContext($inner);
        $handler = new class () implements CallHandler {
            public function handles(CallSite $c, EvalContext $ctx): bool
            {
                return true;
            }

            public function handle(CallSite $c, EvalContext $ctx): Type
            {
                return $c->name === 'honest'
                    ? $ctx->gap(GapCode::UNRESOLVED_CALL, $c->node, 'cannot follow')
                    : new UnknownT();
            }
        };

        $ctx->evaluate($this->node());
        self::assertCount(1, $ctx->violations(), 'evaluate() returned an UnknownT nobody made through gap()');

        $honest = new CallSite($this->node(), null, null, 'honest', []);
        $ctx->handle($handler, $honest);
        self::assertCount(1, $ctx->violations(), 'an honest handler adds no violation');

        $cheat = new CallSite($this->node(), null, null, 'cheat', []);
        $ctx->handle($handler, $cheat);
        self::assertCount(2, $ctx->violations());
    }

    public function testRecordingContextDelegatesToTheWrappedContext(): void
    {
        $inner = new FakeEvalContext();
        $ctx = new RecordingContext($inner);

        $ctx->withFrame(new Frame(new Origin('orders'), false, new Site('app/A.php', 1)), fn () => $ctx->emit($this->select()));
        $ctx->gap(GapCode::DEPTH_LIMIT, $this->node(), 'deep');

        self::assertCount(1, $inner->events());
        self::assertCount(1, $inner->gaps());
        self::assertSame(0, $ctx->seq());
        self::assertSame(1, $ctx->seq());
        self::assertSame($inner->site($this->node())->file, $ctx->site($this->node())->file);
    }

    public function testCallSiteEvaluatesAnArgumentOnlyOnce(): void
    {
        $calls = 0;
        $site = new CallSite($this->node(), null, null, 'm', [new Node\Arg(new Node\Scalar\Int_(1))], function (int $i) use (&$calls): Type {
            $calls++;

            return new PlainT();
        });

        $first = $site->argType(0);
        $second = $site->argType(0);

        self::assertSame($first, $second);
        self::assertSame(1, $calls, 'events of an argument must be emitted once');
        self::assertInstanceOf(Node\Scalar\Int_::class, $site->argNode(0));
        self::assertNull($site->argNode(1));
    }

    public function testCallSiteRejectsAMissingArgument(): void
    {
        $site = new CallSite($this->node(), null, null, 'm', [], static fn (int $i): Type => new PlainT());

        $this->expectException(\OutOfRangeException::class);
        $site->argType(0);
    }

    public function testHandlerRegistryOrdersByPriorityThenRegistration(): void
    {
        $make = static fn (): CallHandler => new class () implements CallHandler {
            public function handles(CallSite $c, EvalContext $ctx): bool
            {
                return false;
            }

            public function handle(CallSite $c, EvalContext $ctx): Type
            {
                return new PlainT();
            }
        };
        [$a, $b, $c, $d] = [$make(), $make(), $make(), $make()];

        $registry = new HandlerRegistry();
        $registry->register($a, 50);
        $registry->register($b, 10);
        $registry->register($c, 50);
        $registry->register($d, 10);

        self::assertSame([$b, $d, $a, $c], $registry->handlers());
    }

    private function select(): CostEvent
    {
        return new CostEvent(EventKind::Select, new Site('app/A.php', 1), [], new QueryPart(), null, 'orders', [], 0);
    }

    private function node(): Node\Expr
    {
        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse("<?php\nfoo();\n");
        self::assertNotNull($ast);
        $stmt = $ast[0];
        self::assertInstanceOf(Node\Stmt\Expression::class, $stmt);

        return $stmt->expr;
    }
}
