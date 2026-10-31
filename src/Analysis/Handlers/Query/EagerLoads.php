<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Handlers\Query;

use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Type\UnknownT;
use PeakSim\Model\EventKind;
use PeakSim\Model\GapCode;
use PeakSim\Model\MemoryCause;
use PeakSim\Model\MemoryPart;
use PeakSim\Model\Origin;
use PeakSim\Model\QueryPart;
use PhpParser\Node;

/**
 * Eager loading: which relation names a query loads (explicit with() plus the $with of every model it
 * touches, D25), and the queries and memory that costs when the query runs.
 *
 * A load is named by its dotted path. "items.product" is two loads, so the name list always holds both
 * "items" and "items.product". One name is one query.
 */
final class EagerLoads
{
    /** What the rows being loaded into are: sets how much memory a to-many load adds, and whether it repeats per window. */
    public const MANY = 'many';
    public const SINGLE = 'single';
    public const BOUNDED = 'bounded';
    public const STREAM = 'stream';

    public function __construct(
        private readonly EvalContext $ctx,
        private readonly ModelFacts $facts,
        private readonly QueryEvents $events,
    ) {
    }

    /**
     * The relations a model loads on its own through $with, with their own $with and so on.
     *
     * @return list<string>
     */
    public function seed(string $class, Node $at): array
    {
        $descriptor = $this->facts->describe($class, $at);
        if ($descriptor instanceof UnknownT) {
            return [];
        }
        $names = [];
        foreach ($this->facts->withOf($descriptor, $at) as $path) {
            $this->walk($class, $path, '', [$class], $at, $names);
        }

        return array_keys($names);
    }

    /**
     * The names that loading these paths on $class adds, the models' own $with included.
     *
     * @param list<string> $paths
     * @return list<string>
     */
    public function expand(string $class, array $paths, Node $at): array
    {
        $names = [];
        foreach ($paths as $path) {
            $this->walk($class, $path, '', [$class], $at, $names);
        }

        return array_keys($names);
    }

    /**
     * @param list<string> $visited the models on the way down, for the cycle check
     * @param array<string, true> $names
     */
    private function walk(string $class, string $path, string $prefix, array $visited, Node $at, array &$names): void
    {
        $current = $class;
        $accumulated = $prefix;
        foreach (explode('.', $path) as $segment) {
            $descriptor = $this->facts->describe($current, $at);
            if ($descriptor instanceof UnknownT) {
                return;
            }
            $relation = $this->facts->relation($descriptor, $segment, $at);
            if ($relation === null || $relation->related === null) {
                $this->ctx->gap(GapCode::RELATION_TARGET_UNKNOWN, $at, $relation === null
                    ? "{$current} has no relation named {$segment}"
                    : "the related model of {$current}::{$segment}() is not known");

                return;
            }
            $accumulated = $accumulated === '' ? $segment : $accumulated . '.' . $segment;
            $names[$accumulated] = true;
            $current = $relation->related;
            $this->ownWith($current, $accumulated, $visited, $at, $names);
        }
    }

    /**
     * @param list<string> $visited
     * @param array<string, true> $names
     */
    private function ownWith(string $class, string $prefix, array $visited, Node $at, array &$names): void
    {
        $descriptor = $this->facts->describe($class, $at);
        if ($descriptor instanceof UnknownT) {
            return;
        }
        $with = $this->facts->withOf($descriptor, $at);
        if ($with === []) {
            return;
        }
        if (in_array($class, $visited, true)) {
            $this->ctx->gap(GapCode::WITH_CYCLE, $at, "the \$with of {$class} leads back to itself: loading stops here");

            return;
        }
        foreach ($with as $path) {
            $this->walk($class, $path, $prefix, [...$visited, $class], $at, $names);
        }
    }

    /**
     * What a loaded relation's rows carry as already loaded: the names below it, with its prefix cut off.
     *
     * @param list<string> $eager
     * @return list<string>
     */
    public function nested(array $eager, string $relation): array
    {
        $prefix = $relation . '.';
        $nested = [];
        foreach ($eager as $name) {
            if (str_starts_with($name, $prefix)) {
                $nested[] = substr($name, strlen($prefix));
            }
        }

        return $nested;
    }

    /**
     * The queries and memory of loading $names into rows of $class.
     *
     * @param list<string> $names in the order the events should appear
     * @param self::* $parent what the rows are
     * @param Origin|null $window for STREAM: the table whose windows these loads repeat for
     */
    public function emit(string $class, array $names, Node $at, string $parent, ?Origin $window = null): void
    {
        $parentDegree = $parent === self::MANY ? 1 : 0;
        foreach ($names as $name) {
            $step = $this->step($class, $name);
            if ($step === null) {
                continue;
            }
            $query = $parent === self::STREAM
                ? new QueryPart(ownUnbounded: true, ownOrigin: $window)
                : new QueryPart();
            $memory = $step['many']
                ? new MemoryPart($parentDegree + $step['manyBefore'] + 1, new Origin($step['table'], $step['ownerTable']), MemoryCause::EagerToMany)
                : null;
            $this->events->statement(EventKind::EagerLoad, $at, $step['table'], $query, $memory);
        }
    }

    /**
     * The last hop of a dotted name: the table it reads, its owner's table, whether it is to-many, and how many
     * to-many hops come before it. Quiet: the names were checked when they were made.
     *
     * @return array{table: ?string, ownerTable: ?string, many: bool, manyBefore: int, related: string}|null
     */
    public function step(string $class, string $name): ?array
    {
        $current = $class;
        $manyBefore = 0;
        $segments = explode('.', $name);
        foreach ($segments as $i => $segment) {
            $owner = $this->facts->peek($current);
            $relation = $owner?->relations[$segment] ?? null;
            if ($owner === null || $relation === null || $relation->related === null) {
                return null;
            }
            if ($i === count($segments) - 1) {
                return [
                    'table' => $this->facts->peek($relation->related)?->table,
                    'ownerTable' => $owner->table,
                    'many' => $relation->many,
                    'manyBefore' => $manyBefore,
                    'related' => $relation->related,
                ];
            }
            $manyBefore += $relation->many ? 1 : 0;
            $current = $relation->related;
        }

        return null;
    }
}
