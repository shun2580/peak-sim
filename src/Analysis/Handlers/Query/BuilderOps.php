<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Handlers\Query;

use PeakSim\Analysis\Contracts\CallHandler;
use PeakSim\Analysis\Contracts\CallSite;
use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Type\BuilderT;
use PeakSim\Analysis\Type\ClosureT;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\ShapeT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Analysis\Type\UnknownT;
use PeakSim\Model\AssumptionCode;
use PeakSim\Model\Axis;
use PeakSim\Model\Cardinality;
use PeakSim\Model\CardinalityKind;
use PeakSim\Model\Delivery;
use PeakSim\Model\EventKind;
use PeakSim\Model\Frame;
use PeakSim\Model\GapCode;
use PeakSim\Model\MemoryCause;
use PeakSim\Model\MemoryPart;
use PeakSim\Model\Origin;
use PeakSim\Model\QueryPart;
use PeakSim\Source\ModelDescriptor;
use PhpParser\Node;

/**
 * Eloquent / query builder methods on a BuilderT: what they read, write and hold in memory.
 *
 * Method names are compared in lower case, as PHP does. Anything it does not know is a gap
 * (UNKNOWN_BUILDER_METHOD), unless the model has a scope of that name, which is walked.
 */
final class BuilderOps implements CallHandler
{
    /** Builder calls that narrow, order or shape the query and change nothing we track. */
    private const PASSTHROUGH = [
        'on', 'onwriteconnection', 'usewritepdo', 'distinct', 'addselect', 'reorder', 'inrandomorder', 'latest', 'oldest',
        'skip', 'offset', 'union', 'unionall', 'lock', 'lockforupdate', 'sharedlock', 'from', 'fromsub', 'fromraw',
        'withtrashed', 'onlytrashed', 'withouttrashed', 'withoutglobalscope', 'withoutglobalscopes', 'withglobalscope',
        'withcasts', 'scopes', 'applyscopes', 'has', 'orhas', 'doesnthave', 'ordoesnthave', 'newquery', 'setmodel',
        'withwhere', 'addbinding', 'mergewheres', 'beforequery',
    ];

    /** Name prefixes of builder calls that are all pass-through: where*, orWhere*, orderBy*, join*, select*, groupBy*, having*. */
    private const PASSTHROUGH_PREFIXES = [
        'where', 'orwhere', 'orderby', 'join', 'leftjoin', 'rightjoin', 'crossjoin', 'select', 'groupby', 'having', 'orhaving',
    ];

    /** Calls that return a plain value and touch no row. */
    private const PLAIN_VALUE = ['tosql', 'torawsql', 'getbindings', 'dump', 'dd', 'dumprawsql', 'ddrawsql', 'getmodel'];

    /** Calls whose first argument names a relation and whose closure constrains the related query. */
    private const RELATION_CONSTRAINTS = [
        'wherehas', 'orwherehas', 'wheredoesnthave', 'orwheredoesnthave', 'has', 'orhas', 'doesnthave', 'ordoesnthave',
        'withcount', 'withsum', 'withavg', 'withmin', 'withmax', 'withexists', 'whererelation', 'orwhererelation',
    ];

    private const AGGREGATES = ['count', 'exists', 'doesntexist', 'sum', 'avg', 'average', 'min', 'max', 'aggregate', 'numericaggregate'];

    private const ONE_ROW = ['first', 'firstorfail', 'sole', 'firstwhere'];

    private const FINDS = ['find', 'findorfail', 'findor', 'findmany'];

    private const CHUNKS = ['chunk', 'chunkbyid', 'chunkbyiddesc', 'orderedchunkbyid', 'chunkmap'];

    private const EACHES = ['each', 'eachbyid'];

    private const LAZIES = ['lazy', 'lazybyid', 'lazybyiddesc'];

    private const DEFAULT_CHUNK = 1000;

    /** Writes that fire model events on any receiver. Instance calls add update/delete/... (see FIRES_ON_INSTANCE). */
    private const FIRES_EVENTS = ['create', 'forcecreate', 'firstorcreate', 'updateorcreate', 'createmany', 'savemany', 'save', 'push', 'touch', 'destroy'];

    private const FIRES_ON_INSTANCE = ['update', 'delete', 'forcedelete', 'restore', 'increment', 'decrement'];

    private const PIVOT_ONE = ['attach', 'detach', 'updateexistingpivot'];

    private const PIVOT_MANY = ['sync', 'syncwithoutdetaching', 'syncwithpivotvalues', 'toggle'];

    public function handles(CallSite $c, EvalContext $ctx): bool
    {
        return $c->receiver instanceof BuilderT && !$c->node instanceof Node\Expr\PropertyFetch;
    }

    public function handle(CallSite $c, EvalContext $ctx): Type
    {
        $b = $c->receiver;
        \assert($b instanceof BuilderT);

        return $this->apply($b, $c, $ctx);
    }

    /** A fresh builder for a model, seeded with what its $with loads. UnknownT (with its gap) when the model is not described. */
    public function newBuilder(string $class, Node $at, QueryKit $k): BuilderT|UnknownT
    {
        $descriptor = $k->facts->describe($class, $at);
        if ($descriptor instanceof UnknownT) {
            return $descriptor;
        }

        return new BuilderT($class, $k->facts->table($descriptor, $at), $k->eager->seed($class, $at));
    }

    /**
     * @param bool $onInstance the call is on a model row (save, update, delete...), not on a query
     */
    public function apply(BuilderT $b, CallSite $c, EvalContext $ctx, bool $onInstance = false): Type
    {
        $k = new QueryKit($ctx);
        $key = strtolower($c->name);

        return match (true) {
            $key === 'with' => $this->with($b, $c, $k),
            $key === 'withonly' => $this->withOnly($b, $c, $k),
            $key === 'without' => $this->without($b, $c, $k),
            $key === 'when' || $key === 'unless' => $this->when($b, $c, $k),
            $key === 'tap' => $this->tap($b, $c, $k),
            $key === 'limit' || $key === 'take' || $key === 'forpage' => $this->bound($b, $c, $k),
            $this->isPassthrough($key) => $this->passthrough($b, $c, $k, $key),
            in_array($key, self::PLAIN_VALUE, true) => $this->passArgs($c, new PlainT()),
            $key === 'get' || $key === 'all' => $this->get($b, $c, $k),
            $key === 'pluck' => $this->pluck($b, $c, $k),
            in_array($key, self::AGGREGATES, true) => $this->aggregate($b, $c, $k),
            $key === 'value' || $key === 'valueorfail' => $this->value($b, $c, $k),
            in_array($key, self::ONE_ROW, true) => $this->first($b, $c, $k),
            in_array($key, self::FINDS, true) => $this->find($b, $c, $k, $key),
            $key === 'paginate' || $key === 'simplepaginate' || $key === 'cursorpaginate' => $this->paginate($b, $c, $k, $key === 'paginate' ? 2 : 1),
            in_array($key, self::CHUNKS, true) => $this->chunk($b, $c, $k),
            in_array($key, self::EACHES, true) => $this->each($b, $c, $k),
            in_array($key, self::LAZIES, true) => $this->lazy($b, $c, $k),
            $key === 'cursor' => $this->cursor($b, $c, $k),
            default => $this->writeOrScope($b, $c, $k, $key, $onInstance),
        };
    }

    // ---- eager loading -------------------------------------------------------------------------------------

    private function with(BuilderT $b, CallSite $c, QueryKit $k): Type
    {
        $parsed = $this->relationPaths($c, $k);
        if ($b->class === null) {
            return $b;
        }
        $names = $k->eager->expand($b->class, $parsed['paths'], $c->node);
        foreach ($parsed['closures'] as $path => $closure) {
            $this->runConstraint($b, $path, $closure, $k);
        }

        return $b->withEager($names);
    }

    private function withOnly(BuilderT $b, CallSite $c, QueryKit $k): Type
    {
        $parsed = $this->relationPaths($c, $k);
        if ($b->class === null) {
            return $b;
        }
        $names = $k->eager->expand($b->class, $parsed['paths'], $c->node);

        return new BuilderT($b->class, $b->table, $names, $b->bounded, $b->relationOf);
    }

    private function without(BuilderT $b, CallSite $c, QueryKit $k): Type
    {
        $parsed = $this->relationPaths($c, $k);
        $kept = array_values(array_filter($b->eager, static function (string $name) use ($parsed): bool {
            foreach ($parsed['paths'] as $path) {
                if ($name === $path || str_starts_with($name, $path . '.')) {
                    return false;
                }
            }

            return true;
        }));

        return new BuilderT($b->class, $b->table, $kept, $b->bounded, $b->relationOf);
    }

    /**
     * The relation paths named by the arguments of with() and friends: 'a', 'a.b', 'a:id,name', ['a', 'b'], ['a' => fn].
     * A name that is not a constant is a gap: the relations it loads are not known.
     *
     * @return array{paths: list<string>, closures: array<string, ClosureT>}
     */
    private function relationPaths(CallSite $c, QueryKit $k): array
    {
        $paths = [];
        $closures = [];
        foreach ($c->args as $i => $arg) {
            $node = $arg->value;
            $type = $c->argType($i);
            if ($node instanceof Node\Expr\Array_) {
                foreach ($node->items as $item) {
                    $keyNode = $item->key;
                    $path = $this->constString($keyNode ?? $item->value, $k);
                    if ($path === null) {
                        $k->ctx->gap(GapCode::RELATION_TARGET_UNKNOWN, $c->node, 'a relation name given to with() is not a constant: the relations it loads are not known');
                        continue;
                    }
                    $path = $this->stripColumns($path);
                    $paths[] = $path;
                    if ($keyNode !== null) {
                        $closure = $type instanceof ShapeT ? ($type->entries[$path] ?? $type->entries[$this->constString($keyNode, $k) ?? ''] ?? null) : null;
                        if ($closure instanceof ClosureT) {
                            $closures[$path] = $closure;
                        }
                    }
                }

                continue;
            }
            $path = $this->constString($node, $k);
            if ($path === null) {
                $k->ctx->gap(GapCode::RELATION_TARGET_UNKNOWN, $c->node, 'a relation name given to with() is not a constant: the relations it loads are not known');
                continue;
            }
            $paths[] = $this->stripColumns($path);
        }

        return ['paths' => $paths, 'closures' => $closures];
    }

    private function stripColumns(string $path): string
    {
        $colon = strpos($path, ':');

        return $colon === false ? $path : substr($path, 0, $colon);
    }

    private function constString(Node $node, QueryKit $k): ?string
    {
        if (!$node instanceof Node\Expr) {
            return null;
        }
        $value = $k->ctx->source()->evaluate($node, null);

        return $value->known && is_string($value->value) ? $value->value : null;
    }

    private function constInt(CallSite $c, int $i, QueryKit $k): ?int
    {
        $node = $c->argNode($i);
        if ($node === null) {
            return null;
        }
        $value = $k->ctx->source()->evaluate($node, null);

        return $value->known && is_int($value->value) ? $value->value : null;
    }

    /** Runs a constraint closure (with(['items' => fn ($q) => ...])) on a builder of the related model, to see what it reads. */
    private function runConstraint(BuilderT $b, ?string $path, ClosureT $closure, QueryKit $k): void
    {
        $target = $b;
        if ($b->class !== null && $path !== null) {
            $step = $k->eager->step($b->class, $this->stripColumns($path));
            if ($step !== null) {
                $target = new BuilderT($step['related'], $step['table']);
            }
        }
        $k->ctx->invokeClosure($closure, [$target]);
    }

    // ---- pass-through and conditionals ---------------------------------------------------------------------

    private function isPassthrough(string $key): bool
    {
        if (in_array($key, self::PASSTHROUGH, true)) {
            return true;
        }
        foreach (self::PASSTHROUGH_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return $key === 'withcount' || $key === 'withsum' || $key === 'withavg' || $key === 'withmin' || $key === 'withmax' || $key === 'withexists' || $key === 'withaggregate';
    }

    private function passthrough(BuilderT $b, CallSite $c, QueryKit $k, string $key): Type
    {
        $relationFirst = in_array($key, self::RELATION_CONSTRAINTS, true);
        $first = $c->argNode(0);
        $relationPath = $relationFirst && $first !== null ? $this->constString($first, $k) : null;
        foreach ($c->args as $i => $_) {
            $type = $c->argType($i);
            if ($type instanceof ClosureT) {
                $this->runConstraint($b, $relationPath, $type, $k);
            } elseif ($relationFirst && $type instanceof ShapeT) {
                foreach ($type->entries as $path => $entry) {
                    if ($entry instanceof ClosureT) {
                        $this->runConstraint($b, (string) $path, $entry, $k);
                    }
                }
            }
        }

        return $b;
    }

    /** Evaluates every argument (so nested queries are seen) and returns $result. */
    private function passArgs(CallSite $c, Type $result): Type
    {
        foreach ($c->args as $i => $_) {
            $c->argType($i);
        }

        return $result;
    }

    /**
     * when($value, $callback, $default) / unless(...): the callbacks run once to see what they read. Which one runs is
     * not known, so the builder that comes out is the worse of the candidates (not bounded, only what all of them load).
     */
    private function when(BuilderT $b, CallSite $c, QueryKit $k): Type
    {
        $value = isset($c->args[0]) ? $c->argType(0) : new PlainT();
        $candidates = [];
        foreach ([1, 2] as $i) {
            if (!isset($c->args[$i])) {
                continue;
            }
            $closure = $k->callback($c->argType($i), $c->node, $c->name . '()');
            if ($closure === null) {
                continue;
            }
            $result = $k->ctx->invokeClosure($closure, [$b, $value]);
            $candidates[] = $result instanceof BuilderT ? $result : $b;
        }

        return $this->worse($b, $candidates, $c, $k);
    }

    private function tap(BuilderT $b, CallSite $c, QueryKit $k): Type
    {
        if (isset($c->args[0])) {
            $closure = $k->callback($c->argType(0), $c->node, 'tap()');
            if ($closure !== null) {
                $k->ctx->invokeClosure($closure, [$b]);
            }
        }

        return $b;
    }

    /**
     * @param list<BuilderT> $candidates
     */
    private function worse(BuilderT $b, array $candidates, CallSite $c, QueryKit $k): BuilderT
    {
        $bounded = $b->bounded;
        $eager = $b->eager;
        $changed = false;
        foreach ($candidates as $candidate) {
            $bounded = $bounded && $candidate->bounded;
            $common = array_values(array_intersect($eager, $candidate->eager));
            $changed = $changed || $candidate->bounded !== $b->bounded || $candidate->eager !== $b->eager;
            $eager = $common;
        }
        if ($changed) {
            $k->ctx->assume(AssumptionCode::BRANCH_WORST_CASE, $c->node, "{$c->name}() may or may not apply its callback: the query that reads more is assumed");
        }

        return new BuilderT($b->class, $b->table, $eager, $bounded, $b->relationOf);
    }

    private function bound(BuilderT $b, CallSite $c, QueryKit $k): Type
    {
        $this->passArgs($c, new PlainT());

        return $b->withBounded();
    }

    // ---- reads ---------------------------------------------------------------------------------------------

    private function cardinality(BuilderT $b, QueryKit $k): Cardinality
    {
        if ($b->bounded) {
            return Cardinality::bounded();
        }
        $per = $b->relationOf !== null ? $k->facts->peek($b->relationOf[0])?->table : null;

        return Cardinality::table($b->table, $per);
    }

    /** The rows a read gives back: model rows, or plain rows for a bare table. */
    private function rows(BuilderT $b, Cardinality $card, Delivery $delivery, bool $window = false, ?array $eager = null): ListT
    {
        $elem = $b->class === null
            ? new PlainT()
            : new ModelT($b->class, $eager ?? $b->eager, $card->kind === CardinalityKind::Table ? $card->origin : null);

        return new ListT($elem, $card, $delivery, $window);
    }

    private function materialize(Cardinality $card): ?MemoryPart
    {
        return $card->kind === CardinalityKind::Table ? QueryEvents::materialize($card->origin) : null;
    }

    private function get(BuilderT $b, CallSite $c, QueryKit $k): Type
    {
        $this->passArgs($c, new PlainT());
        $card = $this->cardinality($b, $k);
        $k->events->statement(EventKind::Select, $c->node, $b->table, new QueryPart(), $this->materialize($card));
        $this->loadEager($b, $c, $k, $card->kind === CardinalityKind::Table ? EagerLoads::MANY : EagerLoads::BOUNDED);

        return $this->rows($b, $card, Delivery::Materialized);
    }

    private function pluck(BuilderT $b, CallSite $c, QueryKit $k): Type
    {
        $this->passArgs($c, new PlainT());
        $card = $this->cardinality($b, $k);
        $k->events->statement(EventKind::Select, $c->node, $b->table, new QueryPart(), $this->materialize($card));

        return new ListT(new PlainT(), $card, Delivery::Materialized);
    }

    private function aggregate(BuilderT $b, CallSite $c, QueryKit $k): Type
    {
        $this->passArgs($c, new PlainT());
        $k->events->statement(EventKind::Aggregate, $c->node, $b->table, new QueryPart());

        return new PlainT();
    }

    private function value(BuilderT $b, CallSite $c, QueryKit $k): Type
    {
        $this->passArgs($c, new PlainT());
        $k->events->statement(EventKind::Select, $c->node, $b->table, new QueryPart());

        return new PlainT();
    }

    private function first(BuilderT $b, CallSite $c, QueryKit $k): Type
    {
        $this->passArgs($c, new PlainT());
        $k->events->statement(EventKind::Select, $c->node, $b->table, new QueryPart());
        $this->loadEager($b, $c, $k, EagerLoads::SINGLE);

        return $b->class === null ? new PlainT() : new ModelT($b->class, $b->eager);
    }

    /** find($id) is a row; find([ids]) and findMany() are rows the size of the list. */
    private function find(BuilderT $b, CallSite $c, QueryKit $k, string $key): Type
    {
        $arg = isset($c->args[0]) ? $c->argType(0) : new PlainT();
        $many = $key === 'findmany' || $arg instanceof ShapeT || $arg instanceof ListT;
        if (!$many) {
            $k->events->statement(EventKind::Select, $c->node, $b->table, new QueryPart());
            $this->loadEager($b, $c, $k, EagerLoads::SINGLE);

            return $b->class === null ? new PlainT() : new ModelT($b->class, $b->eager);
        }
        $card = match (true) {
            $b->bounded || $arg instanceof ShapeT => Cardinality::bounded(),
            $arg instanceof ListT && $arg->card->kind === CardinalityKind::Table => Cardinality::table($b->table),
            $arg instanceof ListT && $arg->card->kind === CardinalityKind::Bounded => Cardinality::bounded(),
            default => Cardinality::unknown(),
        };
        $k->events->statement(EventKind::Select, $c->node, $b->table, new QueryPart(), $this->materialize($card));
        $this->loadEager($b, $c, $k, $card->kind === CardinalityKind::Table ? EagerLoads::MANY : EagerLoads::BOUNDED);

        return $this->rows($b, $card, Delivery::Materialized);
    }

    private function paginate(BuilderT $b, CallSite $c, QueryKit $k, int $statements): Type
    {
        $this->passArgs($c, new PlainT());
        $k->events->statement(EventKind::Select, $c->node, $b->table, new QueryPart(statements: $statements));
        $this->loadEager($b, $c, $k, EagerLoads::BOUNDED);

        return $this->rows($b, Cardinality::bounded(), Delivery::Materialized);
    }

    /** Eager loads of a terminal read, once it is known how many rows they load into. */
    private function loadEager(BuilderT $b, CallSite $c, QueryKit $k, string $parent, ?Origin $window = null): void
    {
        if ($b->class !== null && $b->eager !== []) {
            $k->eager->emit($b->class, $b->eager, $c->node, $parent, $window);
        }
    }

    // ---- streams -------------------------------------------------------------------------------------------

    /** chunk($size, $callback): the callback runs once per window, N/size times. */
    private function chunk(BuilderT $b, CallSite $c, QueryKit $k): Type
    {
        return $this->streamed($b, $c, $k, 1, $this->constInt($c, 0, $k), window: true);
    }

    /** each($callback, $count = 1000): the callback runs once per row. */
    private function each(BuilderT $b, CallSite $c, QueryKit $k): Type
    {
        return $this->streamed($b, $c, $k, 0, $this->constInt($c, 1, $k) ?? self::DEFAULT_CHUNK, window: false);
    }

    private function streamed(BuilderT $b, CallSite $c, QueryKit $k, int $callbackIndex, ?int $size, bool $window): Type
    {
        $this->passArgs($c, new PlainT());
        $callback = isset($c->args[$callbackIndex]) ? $k->callback($c->argType($callbackIndex), $c->node, $c->name . '()') : null;
        if (!isset($c->args[$callbackIndex])) {
            $k->ctx->gap(GapCode::UNRESOLVED_CALL, $c->node, "{$c->name}() was called without a callback");
        }
        $card = $this->cardinality($b, $k);
        $origin = $card->origin ?? new Origin($b->table);
        $k->events->statement(EventKind::Select, $c->node, $b->table, new QueryPart(ownUnbounded: true, ownOrigin: $origin, chunkSize: $size));
        $this->loadEager($b, $c, $k, EagerLoads::STREAM, $origin);
        if ($callback !== null) {
            $rows = $this->rows($b, Cardinality::table($b->table, $origin->perTable), Delivery::Materialized, true);
            $arg = $window ? $rows : $rows->elem;
            $k->ctx->withFrame(
                new Frame($origin, true, $k->ctx->site($c->node)),
                static fn (): Type => $k->ctx->invokeClosure($callback, [$arg]),
            );
        }

        return new PlainT();
    }

    /** lazy($chunk = 1000): a stream of windows read as needed. */
    private function lazy(BuilderT $b, CallSite $c, QueryKit $k): Type
    {
        $this->passArgs($c, new PlainT());
        $size = $this->constInt($c, 0, $k) ?? self::DEFAULT_CHUNK;
        $card = $this->cardinality($b, $k);
        $origin = $card->origin ?? new Origin($b->table);
        $k->events->statement(EventKind::Select, $c->node, $b->table, new QueryPart(ownUnbounded: true, ownOrigin: $origin, chunkSize: $size));
        $this->loadEager($b, $c, $k, EagerLoads::STREAM, $origin);

        return $this->rows($b, Cardinality::table($b->table, $origin->perTable), Delivery::Streamed);
    }

    /**
     * cursor(): one statement; the PDO driver decides whether it holds every row (D20).
     * mysql / mariadb buffer them; sqlite does not; any other driver is assumed to. cursor() loads no relations.
     */
    private function cursor(BuilderT $b, CallSite $c, QueryKit $k): Type
    {
        $this->passArgs($c, new PlainT());
        $card = $this->cardinality($b, $k);
        $origin = $card->origin ?? new Origin($b->table);
        $driver = null;
        if ($b->class !== null) {
            $descriptor = $k->facts->peek($b->class);
            $driver = $descriptor?->driver;
        }
        $degree = 1;
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $degree = 1;
        } elseif ($driver === 'sqlite') {
            $degree = 0;
        } else {
            $k->ctx->assume(
                AssumptionCode::DRIVER_ASSUMED,
                $c->node,
                'the database driver' . ($driver === null ? ' is not known' : " ({$driver}) is not one cursor() was checked on") . ': it is assumed to buffer every row',
                [Axis::Memory],
            );
        }
        $k->events->statement(EventKind::PdoBuffer, $c->node, $b->table, new QueryPart(), new MemoryPart($degree, $origin, MemoryCause::PdoBuffered));

        return $this->rows($b, Cardinality::table($b->table, $origin->perTable), Delivery::Streamed, false, []);
    }

    // ---- writes, relation calls and scopes -----------------------------------------------------------------

    private function writeOrScope(BuilderT $b, CallSite $c, QueryKit $k, string $key, bool $onInstance): Type
    {
        $fires = in_array($key, self::FIRES_EVENTS, true) || ($onInstance && in_array($key, self::FIRES_ON_INSTANCE, true));

        switch ($key) {
            case 'insert':
            case 'insertorignore':
            case 'upsert':
                return $this->bulkWrite($b, $c, $k);
            case 'insertgetid':
            case 'insertusing':
            case 'insertorignoreusing':
            case 'update':
            case 'delete':
            case 'forcedelete':
            case 'truncate':
            case 'increment':
            case 'decrement':
            case 'incrementeach':
            case 'decrementeach':
            case 'restore':
            case 'touch':
            case 'push':
            case 'savequietly':
            case 'updatequietly':
            case 'deletequietly':
                return $this->write($b, $c, $k, new QueryPart(), $fires, new PlainT());
            case 'updateorinsert':
                return $this->write($b, $c, $k, new QueryPart(statements: 2, conditional: true), false, new PlainT());
            case 'create':
            case 'forcecreate':
            case 'createquietly':
            case 'save':
                return $this->write($b, $c, $k, new QueryPart(), $fires, $this->modelOf($b));
            case 'firstorcreate':
            case 'updateorcreate':
            case 'destroy':
                return $this->write($b, $c, $k, new QueryPart(statements: 2, conditional: true), $fires, $this->modelOf($b));
            case 'firstornew':
                return $this->first($b, $c, $k);
            case 'make':
            case 'newmodelinstance':
            case 'findornew':
                $this->passArgs($c, new PlainT());

                return $this->modelOf($b);
            case 'createmany':
            case 'savemany':
                return $this->perElement($b, $c, $k, $fires);
            case 'associate':
            case 'dissociate':
                $this->passArgs($c, new PlainT());

                return $b->relationOf === null ? new PlainT() : new ModelT($b->relationOf[0]);
        }
        if ($b->relationOf !== null && in_array($key, self::PIVOT_ONE, true)) {
            return $this->pivot($b, $c, $k, 1, false);
        }
        if ($b->relationOf !== null && in_array($key, self::PIVOT_MANY, true)) {
            return $this->pivot($b, $c, $k, 3, true);
        }

        return $this->scope($b, $c, $k);
    }

    private function modelOf(BuilderT $b): Type
    {
        return $b->class === null ? new PlainT() : new ModelT($b->class, $b->eager);
    }

    private function write(BuilderT $b, CallSite $c, QueryKit $k, QueryPart $query, bool $fires, Type $result): Type
    {
        $this->passArgs($c, new PlainT());
        $this->observerGap($b, $c, $k, $fires);
        $k->events->statement(EventKind::Write, $c->node, $b->table, $query);

        return $result;
    }

    /** insert / insertOrIgnore / upsert: one statement; a list read from a table is held in memory as the payload. */
    private function bulkWrite(BuilderT $b, CallSite $c, QueryKit $k): Type
    {
        $rows = isset($c->args[0]) ? $c->argType(0) : new PlainT();
        foreach (array_keys($c->args) as $i) {
            if ($i > 0) {
                $c->argType($i);
            }
        }
        $k->events->statement(EventKind::Write, $c->node, $b->table, new QueryPart());
        if ($rows instanceof ListT && $rows->card->kind === CardinalityKind::Table) {
            $k->events->memory(
                EventKind::Payload,
                $c->node,
                $rows->card->origin?->table,
                new MemoryPart(1, $rows->card->origin, MemoryCause::Payload),
            );
        }

        return new PlainT();
    }

    /** createMany / saveMany: the model's create runs once per element, so a list read from a table makes a loop. */
    private function perElement(BuilderT $b, CallSite $c, QueryKit $k, bool $fires): Type
    {
        $list = isset($c->args[0]) ? $c->argType(0) : new PlainT();
        $this->observerGap($b, $c, $k, $fires);
        $emit = static fn (): null => $k->events->statement(EventKind::Write, $c->node, $b->table, new QueryPart());

        if ($list instanceof ListT && $list->card->kind === CardinalityKind::Table && $list->card->origin !== null) {
            $k->ctx->withFrame(new Frame($list->card->origin, false, $k->ctx->site($c->node)), $emit);
        } else {
            if (!$list instanceof ShapeT && !($list instanceof ListT && $list->card->kind === CardinalityKind::Bounded)) {
                $k->ctx->gap(GapCode::LOOP_SOURCE_UNKNOWN, $c->node, "{$c->name}() runs once per element of a list whose size is not known");
            }
            $emit();
        }

        return $this->modelOf($b);
    }

    private function pivot(BuilderT $b, CallSite $c, QueryKit $k, int $statements, bool $conditional): Type
    {
        $this->passArgs($c, new PlainT());
        $k->events->statement(EventKind::Write, $c->node, null, new QueryPart(statements: $statements, conditional: $conditional));

        return new PlainT();
    }

    private function observerGap(BuilderT $b, CallSite $c, QueryKit $k, bool $fires): void
    {
        if (!$fires || $b->class === null) {
            return;
        }
        $descriptor = $k->facts->peek($b->class);
        if ($descriptor instanceof ModelDescriptor && $k->facts->hasObservers($descriptor)) {
            $k->ctx->gap(GapCode::MODEL_OBSERVER_NOT_ANALYZED, $c->node, "{$b->class} has observers: what they run on {$c->name}() is not analysed");
        }
    }

    /** scopeXxx() on the model (and its parents): the body is walked with the builder. Otherwise the call is not known. */
    private function scope(BuilderT $b, CallSite $c, QueryKit $k): Type
    {
        if ($b->class !== null) {
            $method = 'scope' . ucfirst($c->name);
            if ($k->ctx->source()->resolveMethod($b->class, $method)->isFound()) {
                $args = [$b];
                foreach (array_keys($c->args) as $i) {
                    $args[] = $c->argType($i);
                }
                $result = $k->ctx->callMethod($b->class, $method, $args, $c->node, new ModelT($b->class));

                return $result instanceof BuilderT ? $result : $b;
            }
        }
        $this->passArgs($c, new PlainT());

        return $k->ctx->gap(GapCode::UNKNOWN_BUILDER_METHOD, $c->node, "{$c->name}() is not a query builder method the analysis knows, and the model has no scope of that name");
    }
}
