<?php

declare(strict_types=1);

namespace PeakSim\Tests\Support;

use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Type\ClosureT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Analysis\Type\UnknownT;
use PeakSim\Binding\BindingTable;
use PeakSim\Model\Assumption;
use PeakSim\Model\AssumptionCode;
use PeakSim\Model\Axis;
use PeakSim\Model\CostEvent;
use PeakSim\Model\Frame;
use PeakSim\Model\Gap;
use PeakSim\Model\GapCode;
use PeakSim\Model\ScenarioTag;
use PeakSim\Model\Site;
use PeakSim\Source\ModelCatalog;
use PeakSim\Source\SourceIndex;
use PhpParser\Node;

/**
 * A minimal EvalContext for unit-testing handlers without an engine.
 * It keeps what was emitted, what gaps and assumptions were recorded, and the frame / scenario stacks.
 * Everything the engine would do (evaluate, invokeClosure, callMethod) is supplied by the test as a callback.
 */
final class FakeEvalContext implements EvalContext
{
    /** @var list<Frame> */
    private array $frames = [];

    /** @var list<ScenarioTag> */
    private array $scenario = [];

    /** @var list<CostEvent> */
    private array $events = [];

    /** @var list<Gap> */
    private array $gaps = [];

    /** @var list<Assumption> */
    private array $assumptions = [];

    private int $seq = 0;

    /**
     * @param (\Closure(Node\Expr): Type)|null $evaluator
     * @param (\Closure(ClosureT, list<Type>): Type)|null $closureInvoker
     * @param (\Closure(string, string, list<Type>, Node, ?Type): Type)|null $methodCaller
     */
    public function __construct(
        private readonly ?\Closure $evaluator = null,
        private readonly ?\Closure $closureInvoker = null,
        private readonly ?\Closure $methodCaller = null,
        private readonly ?ModelCatalog $models = null,
        private readonly ?SourceIndex $source = null,
        private readonly ?BindingTable $bindings = null,
        private readonly string $file = 'app/Fake.php',
    ) {
    }

    public function frames(): array
    {
        return $this->frames;
    }

    /** Like the engine: the current frames and scenario go in front of the event's own. */
    public function emit(CostEvent $e): void
    {
        $this->events[] = $e->withOuterFrames($this->frames)->withOuterScenario($this->scenario);
    }

    public function gap(GapCode $c, Node $at, string $message, ?array $affects = null): UnknownT
    {
        $this->gaps[] = new Gap($c, $this->site($at), $message, $affects ?? [Axis::Query, Axis::Memory], count($this->frames), $this->scenario);

        return new UnknownT();
    }

    public function assume(AssumptionCode $c, Node $at, string $message, ?array $affects = null, ?array $candidates = null): void
    {
        $this->assumptions[] = new Assumption($c, $this->site($at), $message, $affects ?? [Axis::Query, Axis::Memory], $this->scenario, $candidates);
    }

    public function withFrame(Frame $f, callable $body): mixed
    {
        $this->frames[] = $f;
        try {
            return $body();
        } finally {
            array_pop($this->frames);
        }
    }

    public function withScenario(ScenarioTag $t, callable $body): mixed
    {
        $this->scenario[] = $t;
        try {
            return $body();
        } finally {
            array_pop($this->scenario);
        }
    }

    public function evaluate(Node\Expr $e): Type
    {
        return $this->evaluator === null ? new PlainT() : ($this->evaluator)($e);
    }

    public function invokeClosure(ClosureT $c, array $argTypes): Type
    {
        return $this->closureInvoker === null ? new PlainT() : ($this->closureInvoker)($c, $argTypes);
    }

    public function callMethod(string $class, string $method, array $argTypes, Node $site, ?Type $self = null): Type
    {
        return $this->methodCaller === null ? new PlainT() : ($this->methodCaller)($class, $method, $argTypes, $site, $self);
    }

    public function models(): ModelCatalog
    {
        return $this->models ?? throw new \LogicException('FakeEvalContext was built without a ModelCatalog');
    }

    public function source(): SourceIndex
    {
        return $this->source ?? throw new \LogicException('FakeEvalContext was built without a SourceIndex');
    }

    public function bindings(): BindingTable
    {
        return $this->bindings ?? throw new \LogicException('FakeEvalContext was built without a BindingTable');
    }

    public function site(Node $n): Site
    {
        return new Site($this->file, max(1, $n->getStartLine()));
    }

    public function seq(): int
    {
        return $this->seq++;
    }

    /** @return list<CostEvent> */
    public function events(): array
    {
        return $this->events;
    }

    /** @return list<Gap> */
    public function gaps(): array
    {
        return $this->gaps;
    }

    /** @return list<Assumption> */
    public function assumptions(): array
    {
        return $this->assumptions;
    }
}
