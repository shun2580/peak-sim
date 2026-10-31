<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Engine;

use PeakSim\Analysis\Contracts\HandlerRegistry;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\ResourceT;
use PeakSim\Analysis\Type\ShapeT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Analysis\Type\UnionT;
use PeakSim\Analysis\Type\UnknownT;
use PeakSim\Binding\BindingTable;
use PeakSim\Model\Site;
use PeakSim\Model\GapCode;
use PeakSim\Model\Summary;
use PeakSim\Source\MethodMiss;
use PeakSim\Source\MethodRef;
use PeakSim\Source\ModelCatalog;
use PeakSim\Source\SourceIndex;
use PhpParser\Node;

/**
 * Walks the code a route runs and reports what it costs. It owns the method summaries: a method is walked once
 * per (declaring class, receiver, argument types) and the result is reused wherever the same call appears.
 *
 * A summary is not reused when it holds a depth, recursion or budget gap (those depend on where it was asked for),
 * nor at a level where its own deepest call would pass the depth limit.
 */
final class Engine
{
    private readonly BodyAnalyzer $walker;

    private readonly Walker $concreteWalker;

    private readonly CallResolver $resolver;

    private readonly TypeResolver $types;

    /** @var array<string, array{summary: Summary, height: int}> */
    private array $cache = [];

    /** @var array<string, true> */
    private array $inProgress = [];

    /** @var array<int, UnknownT> every UnknownT made by gap(), by id; kept so an id is never reused */
    private array $issued = [];

    /** @var list<string> */
    private array $violations = [];

    private int $computed = 0;

    private int $reused = 0;

    private int $deepestSeen = 0;

    public function __construct(
        private readonly SourceIndex $source,
        private readonly ModelCatalog $models,
        private readonly BindingTable $bindings,
        private readonly HandlerRegistry $registry,
        private readonly EngineOptions $options = new EngineOptions(),
        private readonly ?ActionReturnHook $returnHook = null,
    ) {
        $this->concreteWalker = new Walker($this);
        $this->walker = $this->concreteWalker;
        $this->resolver = new CallResolver($this, $this->concreteWalker);
        $this->types = new TypeResolver($models);
    }

    /** Summary of a route's action. $method is __invoke for an invokable controller. */
    public function analyzeAction(string $class, string $method = '__invoke'): Summary
    {
        $root = new MethodRun(0, '');
        $resolution = $this->source->resolveMethod($class, $method);
        if ($resolution->method === null) {
            $code = match ($resolution->miss) {
                MethodMiss::VendorParent => GapCode::VENDOR_PARENT,
                MethodMiss::TraitConflict => GapCode::TRAIT_CONFLICT,
                default => GapCode::METHOD_NOT_FOUND,
            };
            $info = $this->source->findClass($class);
            $ctx = new EvalContextImpl($this, $root, new Scope($info?->file ?? '', $class, $class, null));
            $ctx->gapAtSite($code, new Site($info?->file ?? '', max(1, $info?->node->getStartLine() ?? 1)), "action {$class}::{$method}() not found");

            return new Summary([], $root->gaps, [], [], new PlainT(), false);
        }
        $self = $this->types->forClass($class);
        $outcome = $this->summarize($resolution->method, $class, $self, [], [], $root);

        return $outcome->summary;
    }

    /**
     * The summary of one method for the given receiver and arguments (positional, then by name).
     *
     * @param list<Type> $args
     * @param array<string, Type> $named
     */
    public function summarize(MethodRef $ref, string $receiverClass, ?Type $self, array $args, array $named, MethodRun $caller): SummaryOutcome
    {
        $level = $caller->level + 1;
        $empty = static fn (GapCode $why): SummaryOutcome => new SummaryOutcome(new Summary([], [], [], [], new PlainT()), $level, true, $why);
        if ($level > $this->options->maxDepth) {
            return $empty(GapCode::DEPTH_LIMIT);
        }

        $key = $this->summaryKey($ref, $receiverClass, $self, $args, $named);
        if (isset($this->inProgress[$key])) {
            return $empty(GapCode::RECURSION);
        }
        if (isset($this->cache[$key]) && $level + $this->cache[$key]['height'] <= $this->options->maxDepth) {
            $this->reused++;

            return new SummaryOutcome($this->cache[$key]['summary'], $level + $this->cache[$key]['height'], false);
        }
        if ($this->computed >= $this->options->budget) {
            return $empty(GapCode::BUDGET_EXCEEDED);
        }

        $this->computed++;
        $this->inProgress[$key] = true;
        try {
            $run = new MethodRun($level, $ref->file);
            $summary = $this->walkMethod($ref, $receiverClass, $self, $args, $named, $run);
        } finally {
            unset($this->inProgress[$key]);
        }
        $this->deepestSeen = max($this->deepestSeen, $run->deepest);
        if (!$run->poisoned) {
            $this->cache[$key] = ['summary' => $summary, 'height' => $run->deepest - $level];
        }

        return new SummaryOutcome($summary, $run->deepest, $run->poisoned);
    }

    /**
     * @param list<Type> $args
     * @param array<string, Type> $named
     */
    private function walkMethod(MethodRef $ref, string $receiverClass, ?Type $self, array $args, array $named, MethodRun $run): Summary
    {
        $node = $ref->node;
        $viaTrait = $ref->via !== null;
        $scope = new Scope($ref->file, $viaTrait ? $receiverClass : $ref->owner, $viaTrait ? $receiverClass : $ref->owner, $self);
        $run->trace[] = new Site($ref->file, max(1, $node->getStartLine()), $ref->owner . '::' . $node->name->toString() . '()');
        $this->resolver->bindParameters($node->params, $args, $named, $scope, $run, false);

        $returned = $this->walker->analyzeBody($node->stmts ?? [], $scope, $run, $node);
        $declared = $this->types->fromDeclaration($node->returnType, $scope->class, $scope->declClass, $this->parentOf(...));
        $return = $returned ?? $declared ?? new PlainT();
        if ($returned instanceof PlainT && $declared !== null && !$declared instanceof PlainT) {
            $return = $declared;
        }

        if ($run->level === 1 && $this->returnHook !== null) {
            $this->returnHook->onActionReturn($return, $node, new EvalContextImpl($this, $run, $scope));
        }

        return new Summary($run->events, $run->gaps, $run->assumptions, $run->trace, $return, $run->truncated);
    }

    /**
     * @param list<Type> $args
     * @param array<string, Type> $named
     */
    private function summaryKey(MethodRef $ref, string $receiverClass, ?Type $self, array $args, array $named): string
    {
        $named = array_map(static fn (Type $t): string => $t->fingerprint(), $named);
        ksort($named, SORT_STRING);
        $namedKey = [];
        foreach ($named as $name => $print) {
            $namedKey[] = $name . '=' . $print;
        }

        return $ref->owner . '::' . $ref->node->name->toString() . '@' . $receiverClass . '|' . ($self?->fingerprint() ?? '-')
            . '|' . implode(',', array_map(static fn (Type $t): string => $t->fingerprint(), $args))
            . '|' . implode(',', $namedKey);
    }

    public function issueUnknown(): UnknownT
    {
        $unknown = new UnknownT();
        $this->issued[spl_object_id($unknown)] = $unknown;

        return $unknown;
    }

    /** Invariant I1, checked only when EngineOptions::$auditUnknown is on. */
    public function audit(Type $type, string $where): void
    {
        if ($this->containsForeignUnknown($type)) {
            $this->violations[] = 'UnknownT without a gap: ' . $where;
        }
    }

    /** @return list<string> */
    public function violations(): array
    {
        return $this->violations;
    }

    /** @return array{summaries_computed: int, summaries_reused: int, deepest_level: int} */
    public function stats(): array
    {
        return ['summaries_computed' => $this->computed, 'summaries_reused' => $this->reused, 'deepest_level' => $this->deepestSeen];
    }

    public function walker(): BodyAnalyzer
    {
        return $this->walker;
    }

    public function resolver(): CallResolver
    {
        return $this->resolver;
    }

    public function types(): TypeResolver
    {
        return $this->types;
    }

    public function options(): EngineOptions
    {
        return $this->options;
    }

    public function registry(): HandlerRegistry
    {
        return $this->registry;
    }

    public function source(): SourceIndex
    {
        return $this->source;
    }

    public function models(): ModelCatalog
    {
        return $this->models;
    }

    public function bindings(): BindingTable
    {
        return $this->bindings;
    }

    public function parentOf(string $class): ?string
    {
        return $this->source->findClass($class)?->parent;
    }

    private function containsForeignUnknown(Type $type): bool
    {
        return match (true) {
            $type instanceof UnknownT => !isset($this->issued[spl_object_id($type)]),
            $type instanceof ListT => $this->containsForeignUnknown($type->elem),
            $type instanceof ResourceT => $this->containsForeignUnknown($type->inner),
            $type instanceof ShapeT => array_reduce($type->entries, fn (bool $c, Type $t): bool => $c || $this->containsForeignUnknown($t), false),
            $type instanceof UnionT => array_reduce($type->members, fn (bool $c, Type $t): bool => $c || $this->containsForeignUnknown($t), false),
            default => false,
        };
    }
}
