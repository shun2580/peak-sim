<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Serialization;

use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\ResourceT;
use PeakSim\Analysis\Type\ShapeT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Analysis\Type\UnionT;
use PeakSim\Model\CardinalityKind;
use PeakSim\Model\Delivery;
use PeakSim\Model\Frame;
use PeakSim\Model\GapCode;
use PeakSim\Model\Origin;
use PhpParser\Node;

/**
 * What happens when a value is turned into JSON: every model in it is serialised (its $appends accessors run, the
 * relations it carries are serialised too) and every resource in it runs its toArray(). The value is walked by its
 * type; each list is a loop whose frame is the list's own origin, so the cost per row is counted per row.
 *
 * Serialising is not free of recursion (a resource that contains itself, a model that appends itself), so every
 * model and resource enters a guard first: the same one twice on the way down is a RECURSION gap, and nesting
 * deeper than MAX_DEPTH is a DEPTH_LIMIT gap.
 */
final class Serializer
{
    private const MAX_DEPTH = 20;

    /** @var list<string> */
    private array $stack = [];

    private readonly ModelSerializer $models;

    private readonly ResourceSerializer $resources;

    public function __construct()
    {
        $this->models = new ModelSerializer($this);
        $this->resources = new ResourceSerializer($this);
    }

    public function serialize(Type $value, Node $at, EvalContext $ctx): void
    {
        switch (true) {
            case $value instanceof ModelT:
                $this->models->serialize($value, $at, $ctx);

                return;
            case $value instanceof ResourceT:
                $this->resources->serialize($value, $at, $ctx);

                return;
            case $value instanceof ListT:
                $this->overRows($value, $at, $ctx, fn (Type $elem) => $this->serialize($elem, $at, $ctx));

                return;
            case $value instanceof ShapeT:
                foreach ($value->entries as $entry) {
                    $this->serialize($entry, $at, $ctx);
                }

                return;
            case $value instanceof UnionT:
                foreach ($value->members as $member) {
                    $this->serialize($member, $at, $ctx);
                }

                return;
            default:
                // PlainT, UnknownT (its gap is already recorded), a closure or a builder: nothing is serialised.
                return;
        }
    }

    /**
     * Run $each for the rows of a list: once inside a frame for a list that grows with a table, once and bare for a
     * bounded list or a chunk window, and once for a list of unknown size, which is a gap if what it does is anything.
     *
     * @param \Closure(Type): mixed $each
     */
    public function overRows(ListT $list, Node $at, EvalContext $ctx, \Closure $each): void
    {
        $elem = $list->elem;
        if ($list->card->kind === CardinalityKind::Table && !$list->window) {
            $origin = $list->card->origin ?? new Origin(null);
            if ($elem instanceof ModelT && $elem->origin === null) {
                $elem = $elem->withOrigin($origin);
            }
            $ctx->withFrame(new Frame($origin, $list->delivery === Delivery::Streamed, $ctx->site($at)), static fn () => $each($elem));

            return;
        }
        if ($list->card->kind === CardinalityKind::Unknown) {
            $this->onceOfUnknownSize($at, $ctx, static fn () => $each($elem));

            return;
        }
        $each($elem);
    }

    /** Run $body once for a collection whose size is not known; if it did anything, that is a loop we cannot count. */
    public function onceOfUnknownSize(Node $at, EvalContext $ctx, \Closure $body): void
    {
        $before = $ctx->seq();
        $body();
        if ($ctx->seq() > $before + 1) {
            $ctx->gap(GapCode::LOOP_SOURCE_UNKNOWN, $at, 'a collection whose size is not known is serialised, and serialising its rows does something');
        }
    }

    /**
     * Enter the guard for $key, run $body, leave. Refuses (with a gap) when $key is already being serialised or the
     * nesting is too deep.
     *
     * @param \Closure(): mixed $body
     */
    public function guarded(string $key, string $what, Node $at, EvalContext $ctx, \Closure $body): void
    {
        if (in_array($key, $this->stack, true)) {
            $ctx->gap(GapCode::RECURSION, $at, "serialising {$what} leads back to itself: not followed");

            return;
        }
        if (count($this->stack) >= self::MAX_DEPTH) {
            $ctx->gap(GapCode::DEPTH_LIMIT, $at, 'serialisation nests more than ' . self::MAX_DEPTH . " levels deep before {$what}");

            return;
        }
        $this->stack[] = $key;
        try {
            $body();
        } finally {
            array_pop($this->stack);
        }
    }
}
