<?php

declare(strict_types=1);

namespace PeakSim\Tests\Support;

use PeakSim\Analysis\Contracts\CallHandler;
use PeakSim\Analysis\Contracts\CallSite;
use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Type\ClosureT;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ResourceT;
use PeakSim\Analysis\Type\ShapeT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Analysis\Type\UnionT;
use PeakSim\Analysis\Type\UnknownT;
use PeakSim\Binding\BindingTable;
use PeakSim\Model\AssumptionCode;
use PeakSim\Model\CostEvent;
use PeakSim\Model\Frame;
use PeakSim\Model\GapCode;
use PeakSim\Model\ScenarioTag;
use PeakSim\Model\Site;
use PeakSim\Source\ModelCatalog;
use PeakSim\Source\SourceIndex;
use PhpParser\Node;

/**
 * Wraps any EvalContext and audits invariant I1: an UnknownT is made only together with a gap.
 *
 * Every UnknownT that EvalContext::gap() returns is remembered by identity. A value that comes back from
 * evaluate / callMethod / invokeClosure / a handler and contains an UnknownT the context never handed out
 * was made without a gap; it is recorded in violations(). Tests assert violations() is empty.
 */
final class RecordingContext implements EvalContext
{
    /** @var array<int, UnknownT> the UnknownT instances made by gap(), by spl_object_id; kept so an id is never reused */
    private array $issued = [];

    /** @var list<string> */
    private array $violations = [];

    private int $gapCalls = 0;

    public function __construct(private readonly EvalContext $inner)
    {
    }

    /** @return list<string> descriptions of each UnknownT that was made without a gap */
    public function violations(): array
    {
        return $this->violations;
    }

    public function gapCalls(): int
    {
        return $this->gapCalls;
    }

    /** Runs a handler and audits what it returns. */
    public function handle(CallHandler $handler, CallSite $call): Type
    {
        return $this->audit($handler->handle($call, $this), 'handler ' . $handler::class . ' for ' . $call->name . '()');
    }

    /** Audits any Type a test got from the code under test. */
    public function audit(Type $type, string $where): Type
    {
        if ($this->containsUnissuedUnknown($type)) {
            $this->violations[] = 'UnknownT without a gap: ' . $where;
        }

        return $type;
    }

    public function frames(): array
    {
        return $this->inner->frames();
    }

    public function emit(CostEvent $e): void
    {
        $this->inner->emit($e);
    }

    public function gap(GapCode $c, Node $at, string $message, ?array $affects = null): UnknownT
    {
        $unknown = $this->inner->gap($c, $at, $message, $affects);
        $this->issued[spl_object_id($unknown)] = $unknown;
        $this->gapCalls++;

        return $unknown;
    }

    public function assume(AssumptionCode $c, Node $at, string $message, ?array $affects = null, ?array $candidates = null): void
    {
        $this->inner->assume($c, $at, $message, $affects, $candidates);
    }

    public function withFrame(Frame $f, callable $body): mixed
    {
        return $this->inner->withFrame($f, $body);
    }

    public function withScenario(ScenarioTag $t, callable $body): mixed
    {
        return $this->inner->withScenario($t, $body);
    }

    public function evaluate(Node\Expr $e): Type
    {
        return $this->audit($this->inner->evaluate($e), 'evaluate() at line ' . $e->getStartLine());
    }

    public function invokeClosure(ClosureT $c, array $argTypes): Type
    {
        return $this->audit($this->inner->invokeClosure($c, $argTypes), 'invokeClosure() at line ' . $c->node->getStartLine());
    }

    public function callMethod(string $class, string $method, array $argTypes, Node $site, ?Type $self = null): Type
    {
        return $this->audit($this->inner->callMethod($class, $method, $argTypes, $site, $self), 'callMethod() ' . $class . '::' . $method);
    }

    public function models(): ModelCatalog
    {
        return $this->inner->models();
    }

    public function source(): SourceIndex
    {
        return $this->inner->source();
    }

    public function bindings(): BindingTable
    {
        return $this->inner->bindings();
    }

    public function site(Node $n): Site
    {
        return $this->inner->site($n);
    }

    public function seq(): int
    {
        return $this->inner->seq();
    }

    private function containsUnissuedUnknown(Type $type): bool
    {
        if ($type instanceof UnknownT) {
            return !isset($this->issued[spl_object_id($type)]);
        }
        if ($type instanceof ListT) {
            return $this->containsUnissuedUnknown($type->elem);
        }
        if ($type instanceof ResourceT) {
            return $this->containsUnissuedUnknown($type->inner);
        }
        if ($type instanceof ShapeT) {
            foreach ($type->entries as $entry) {
                if ($this->containsUnissuedUnknown($entry)) {
                    return true;
                }
            }
        }
        if ($type instanceof UnionT) {
            foreach ($type->members as $member) {
                if ($this->containsUnissuedUnknown($member)) {
                    return true;
                }
            }
        }

        return false;
    }
}
