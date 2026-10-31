<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Contracts;

use PeakSim\Analysis\Type\ClosureT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Analysis\Type\UnknownT;
use PeakSim\Binding\BindingTable;
use PeakSim\Model\Axis;
use PeakSim\Model\AssumptionCode;
use PeakSim\Model\CostEvent;
use PeakSim\Model\Frame;
use PeakSim\Model\GapCode;
use PeakSim\Model\ScenarioTag;
use PeakSim\Model\Site;
use PeakSim\Source\ModelCatalog;
use PeakSim\Source\SourceIndex;
use PhpParser\Node;

/** What a handler may do to the walk in progress. */
interface EvalContext
{
    /** @return list<Frame> the loops around the current position, outermost first */
    public function frames(): array;

    /** The context adds the current frames and scenario; handlers do not pass frames. */
    public function emit(CostEvent $e): void;

    /**
     * Record a gap and return the UnknownT to use as the result (invariant I1).
     *
     * @param list<Axis>|null $affects null = both axes
     */
    public function gap(GapCode $c, Node $at, string $message, ?array $affects = null): UnknownT;

    /**
     * @param list<Axis>|null $affects null = both axes
     * @param array<array-key, mixed>|null $candidates
     */
    public function assume(AssumptionCode $c, Node $at, string $message, ?array $affects = null, ?array $candidates = null): void;

    public function withFrame(Frame $f, callable $body): mixed;

    public function withScenario(ScenarioTag $t, callable $body): mixed;

    public function evaluate(Node\Expr $e): Type;

    /** Evaluate the body once. Its events appear under the current frames. @param list<Type> $argTypes */
    public function invokeClosure(ClosureT $c, array $argTypes): Type;

    /** @param list<Type> $argTypes */
    public function callMethod(string $class, string $method, array $argTypes, Node $site, ?Type $self = null): Type;

    public function models(): ModelCatalog;

    public function source(): SourceIndex;

    public function bindings(): BindingTable;

    public function site(Node $n): Site;

    /** The next depth-first discovery number. */
    public function seq(): int;
}
