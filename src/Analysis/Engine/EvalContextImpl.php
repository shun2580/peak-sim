<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Engine;

use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Type\ClosureT;
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

/** What handlers get: the engine's view of one position (a run and the scope there). Cheap to make. */
final class EvalContextImpl implements EvalContext
{
    public function __construct(
        private readonly Engine $engine,
        private readonly MethodRun $run,
        private readonly Scope $scope,
    ) {
    }

    public function frames(): array
    {
        return $this->run->frames;
    }

    public function emit(CostEvent $e): void
    {
        $this->run->addEvent($e->withOuterFrames($this->run->frames)->withOuterScenario($this->run->scenario));
    }

    public function gap(GapCode $c, Node $at, string $message, ?array $affects = null): UnknownT
    {
        return $this->gapAtSite($c, $this->site($at), $message, $affects);
    }

    public function gapAtSite(GapCode $c, Site $site, string $message, ?array $affects = null): UnknownT
    {
        $this->run->addGap(new Gap(
            $c,
            $site,
            $message,
            $affects ?? [Axis::Query, Axis::Memory],
            count($this->run->frames),
            $this->run->scenario,
        ));

        return $this->engine->issueUnknown();
    }

    public function assume(AssumptionCode $c, Node $at, string $message, ?array $affects = null, ?array $candidates = null): void
    {
        $this->run->addAssumption(new Assumption(
            $c,
            $this->site($at),
            $message,
            $affects ?? [Axis::Query, Axis::Memory],
            $this->run->scenario,
            $candidates,
        ));
    }

    public function withFrame(Frame $f, callable $body): mixed
    {
        $this->run->frames[] = $f;
        try {
            return $body();
        } finally {
            array_pop($this->run->frames);
        }
    }

    public function withScenario(ScenarioTag $t, callable $body): mixed
    {
        $this->run->scenario[] = $t;
        try {
            return $body();
        } finally {
            array_pop($this->run->scenario);
        }
    }

    public function evaluate(Node\Expr $e): Type
    {
        return $this->engine->walker()->evaluate($e, $this->scope, $this->run);
    }

    public function invokeClosure(ClosureT $c, array $argTypes): Type
    {
        return $this->engine->walker()->invokeClosure($c, $argTypes, $this->run);
    }

    public function callMethod(string $class, string $method, array $argTypes, Node $site, ?Type $self = null): Type
    {
        return $this->engine->resolver()->callOnClass($class, $method, $argTypes, [], $site, $self, $this->scope, $this->run);
    }

    public function models(): ModelCatalog
    {
        return $this->engine->models();
    }

    public function source(): SourceIndex
    {
        return $this->engine->source();
    }

    public function bindings(): BindingTable
    {
        return $this->engine->bindings();
    }

    public function site(Node $n): Site
    {
        return new Site($this->scope->file, max(1, $n->getStartLine()));
    }

    public function seq(): int
    {
        return $this->run->nextSeq();
    }
}
