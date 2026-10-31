<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Handlers\Cache;

use PeakSim\Analysis\Contracts\CallHandler;
use PeakSim\Analysis\Contracts\CallSite;
use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Type\ClosureT;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\ObjectT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\ResourceT;
use PeakSim\Analysis\Type\ShapeT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Analysis\Type\UnionT;
use PeakSim\Analysis\Type\UnknownT;
use PeakSim\Binding\BindingResultKind;
use PeakSim\Model\Axis;
use PeakSim\Model\AssumptionCode;
use PeakSim\Model\CardinalityKind;
use PeakSim\Model\CostEvent;
use PeakSim\Model\EventKind;
use PeakSim\Model\GapCode;
use PeakSim\Model\MemoryCause;
use PeakSim\Model\MemoryPart;
use PeakSim\Model\Origin;
use PeakSim\Model\ScenarioTag;
use PhpParser\Node;
use PhpParser\Node\Expr;

/**
 * `Cache::remember()` and its kin (`rememberForever`, `sear`, `flexible`), as the facade, `cache()->…` or an
 * injected repository.
 *
 * A cached call has two lives. On a MISS the closure runs: its events and gaps are tagged `miss`. On a HIT the
 * closure does not run, but what it returned comes back out of the store: a `CacheRestore` event tagged `hit`
 * carries that memory. The Judge keeps the two views apart (a hit has no query; a miss has no restore), and takes
 * the worse one as the route's verdict.
 *
 *  - The restore is memory degree 1 when the closure returns a table-sized list (one more for each to-many relation
 *    loaded in its rows, one more for each list nested in it); no memory part at all when it returns a scalar, a row
 *    or a bounded list. A scalar restore is deliberately NOT an event with degree 0: inside a loop the Judge counts
 *    the loop's frames into a restore's degree, and one string per iteration is not "restored in full".
 *  - When the restore grows, memory on a hit rests on the cache driver serialising its values (decision D23):
 *    Assumption CACHE_DRIVER_SERIALIZING, on the hit view and the memory axis only. The miss view is not touched.
 *  - If the size of what the closure returns is not known (an unknown closure, an object, a list of unknown size),
 *    gap CACHE_VALUE_SIZE_UNKNOWN on the hit view's memory axis.
 *  - The call's type is the closure's type, so a later loop over the result still knows where its N comes from.
 *
 * The cache call's id (the tag's `cacheSite`) is a hash of the file and the position of the call, so the same call
 * reached by two paths is one cache, and two calls are two. Not the engine's seq(), which restarts in every method.
 *
 * It is the allow-list's business to leave these calls alone (they are `delegate: CacheOps` in known-calls.php); the
 * two files are tied by a test.
 */
final class CacheOps implements CallHandler
{
    public const FACADE = 'Illuminate\\Support\\Facades\\Cache';

    /** The facade's global alias, as it is written in a file with no namespace. */
    public const FACADE_ALIAS = 'Cache';

    /** What `cache()` returns first, and what an injected cache store is typed as. */
    public const RECEIVERS = [
        'Illuminate\\Cache\\CacheManager',
        'Illuminate\\Cache\\Repository',
        'Illuminate\\Contracts\\Cache\\Repository',
    ];

    /** method (lower case) => the position of its callback among the positional arguments */
    public const METHODS = [
        'flexible' => 2,
        'remember' => 2,
        'rememberforever' => 1,
        'sear' => 1,
    ];

    public function handles(CallSite $c, EvalContext $ctx): bool
    {
        if (!isset(self::METHODS[strtolower($c->name)])) {
            return false;
        }
        $node = $c->node;
        if ($node instanceof Expr\StaticCall) {
            return $c->receiver === null
                && $c->staticClass !== null
                && in_array(strtolower($c->staticClass), [strtolower(self::FACADE), strtolower(self::FACADE_ALIAS)], true);
        }
        if ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) {
            return $c->receiver instanceof ObjectT
                && in_array(strtolower(ltrim($c->receiver->class, '\\')), array_map('strtolower', self::RECEIVERS), true)
                && $ctx->bindings()->resolve($c->receiver->class)->kind === BindingResultKind::None;
        }

        return false;
    }

    public function handle(CallSite $c, EvalContext $ctx): Type
    {
        $callbackAt = $this->callbackIndex($c);
        foreach (array_keys($c->args) as $i) {
            if ($i !== $callbackAt) {
                $c->argType($i);
            }
        }
        $callback = $callbackAt === null ? null : $c->argType($callbackAt);

        $id = $this->cacheId($c->node, $ctx);
        $miss = new ScenarioTag($id, ScenarioTag::MISS);
        $hit = new ScenarioTag($id, ScenarioTag::HIT);

        $returned = $ctx->withScenario($miss, function () use ($callback, $c, $ctx): Type {
            if ($callback instanceof ClosureT) {
                return $ctx->invokeClosure($callback, []);
            }
            if ($callback instanceof UnknownT) {
                return $callback;
            }

            return $ctx->gap(GapCode::UNRESOLVED_CALL, $c->node, "the callback of {$c->name}() is not a closure the analysis can run: what it does on a miss is not known");
        });

        $ctx->withScenario($hit, function () use ($returned, $c, $ctx): void {
            $this->restore($returned, $c->node, $ctx);
        });

        return $returned;
    }

    private function restore(Type $returned, Node $at, EvalContext $ctx): void
    {
        $size = $this->sizeOf($returned, $ctx);
        if ($size['unknown']) {
            $ctx->gap(GapCode::CACHE_VALUE_SIZE_UNKNOWN, $at, 'what the cached closure returns has a size the analysis cannot tell, so what a hit restores is not known', [Axis::Memory]);
        }
        $origin = $size['origin'];
        $memory = $size['degree'] > 0 ? new MemoryPart($size['degree'], $origin, MemoryCause::CacheRestore) : null;
        $ctx->emit(new CostEvent(EventKind::CacheRestore, $ctx->site($at), [], null, $memory, $origin?->table, [], $ctx->seq()));
        if ($size['degree'] > 0) {
            $ctx->assume(
                AssumptionCode::CACHE_DRIVER_SERIALIZING,
                $at,
                'a hit restores the cached list from the store; its memory grows with it only if the cache driver serialises the value (it does for file, database and redis)',
                [Axis::Memory],
            );
        }
    }

    /**
     * How many table-sized layers a value holds, and where the outermost comes from.
     *
     * @return array{degree: int, origin: Origin|null, unknown: bool}
     */
    private function sizeOf(Type $t, EvalContext $ctx): array
    {
        if ($t instanceof PlainT) {
            return self::size(0, null, false);
        }
        if ($t instanceof ModelT) {
            return $this->rowSize($t, $ctx);
        }
        if ($t instanceof ListT) {
            $elem = $this->sizeOf($t->elem, $ctx);
            if ($t->card->kind === CardinalityKind::Unknown) {
                return self::size($elem['degree'], $elem['origin'], true);
            }
            if ($t->card->kind === CardinalityKind::Table) {
                return self::size(1 + $elem['degree'], $t->card->origin ?? new Origin(null), $elem['unknown']);
            }

            return $elem;
        }
        if ($t instanceof ShapeT) {
            return self::widest(array_map(fn (Type $e): array => $this->sizeOf($e, $ctx), array_values($t->entries)));
        }
        if ($t instanceof UnionT) {
            return self::widest(array_map(fn (Type $e): array => $this->sizeOf($e, $ctx), $t->members));
        }
        if ($t instanceof ResourceT) {
            return $this->sizeOf($t->inner, $ctx);
        }

        // UnknownT, an object, a builder, a closure: how big it is when restored cannot be told.
        return self::size(0, null, true);
    }

    /**
     * One row costs nothing extra, except for the to-many relations already loaded into it: they come back with it.
     *
     * @return array{degree: int, origin: Origin|null, unknown: bool}
     */
    private function rowSize(ModelT $row, EvalContext $ctx): array
    {
        $degree = 0;
        $unknown = false;
        foreach ($row->eager as $path) {
            $class = $row->class;
            $many = 0;
            foreach (explode('.', $path) as $name) {
                $relation = $ctx->models()->describe($class)?->relations[$name] ?? null;
                if ($relation === null || $relation->related === null) {
                    $unknown = true;
                    break;
                }
                $many += $relation->many ? 1 : 0;
                $class = $relation->related;
            }
            $degree = max($degree, $many);
        }

        return self::size($degree, $degree > 0 ? ($row->origin ?? new Origin(null)) : null, $unknown);
    }

    /**
     * @param list<array{degree: int, origin: Origin|null, unknown: bool}> $sizes
     * @return array{degree: int, origin: Origin|null, unknown: bool}
     */
    private static function widest(array $sizes): array
    {
        $best = self::size(0, null, false);
        foreach ($sizes as $size) {
            if ($size['degree'] > $best['degree']) {
                $best = self::size($size['degree'], $size['origin'], $best['unknown']);
            }
            $best['unknown'] = $best['unknown'] || $size['unknown'];
        }

        return $best;
    }

    /** @return array{degree: int, origin: Origin|null, unknown: bool} */
    private static function size(int $degree, ?Origin $origin, bool $unknown): array
    {
        return ['degree' => $degree, 'origin' => $origin, 'unknown' => $unknown];
    }

    /** The callback: the argument named `callback`, else the positional one this method takes it in. */
    private function callbackIndex(CallSite $c): ?int
    {
        foreach ($c->args as $i => $arg) {
            if ($arg->name?->toString() === 'callback') {
                return $i;
            }
        }
        $position = self::METHODS[strtolower($c->name)];
        $arg = $c->args[$position] ?? null;

        return $arg !== null && $arg->name === null ? $position : null;
    }

    private function cacheId(Node $node, EvalContext $ctx): int
    {
        $place = $node->getStartFilePos();

        return crc32($ctx->site($node)->file . ':' . ($place >= 0 ? $place : 'l' . $node->getStartLine()));
    }
}
