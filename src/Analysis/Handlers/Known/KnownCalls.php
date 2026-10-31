<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Handlers\Known;

use PeakSim\Analysis\Contracts\CallHandler;
use PeakSim\Analysis\Contracts\CallSite;
use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Type\BuilderT;
use PeakSim\Analysis\Type\ClosureT;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\ObjectT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\ShapeT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Analysis\Type\UnionT;
use PeakSim\Analysis\Type\UnknownT;
use PeakSim\Binding\BindingResultKind;
use PeakSim\Model\GapCode;
use PeakSim\Source\ParseFailed;
use PhpParser\Node\Expr;

/**
 * The vendor calls that are known not to touch the database or call back into the app (resources/known-calls.php).
 *
 * A call on the list comes out as a plain value (or the type the list names). Every other call on a vendor function,
 * a vendor class's static method or a vendor object is a gap VENDOR_CALL_NOT_MODELLED, and its result is the
 * UnknownT that the gap returns (invariant I1): "not known" is never turned into "safe".
 *
 * What it leaves alone, for the engine or another handler: calls on app classes (the engine follows their bodies);
 * vendor types the app binds to its own class (the engine follows the binding); receivers that are not vendor
 * objects (a model, a builder, a list, a plain value); `new`; property reads; and every call the list marks as
 * delegated (view(), the response sink, the cache family).
 *
 * Register it LAST. It answers every vendor function call, so a handler placed after it never sees the calls it
 * would have taken.
 */
final class KnownCalls implements CallHandler
{
    private readonly KnownTable $table;

    public function __construct(?KnownTable $table = null)
    {
        $this->table = $table ?? KnownTable::default();
    }

    public function handles(CallSite $c, EvalContext $ctx): bool
    {
        $node = $c->node;
        if ($node instanceof Expr\FuncCall) {
            return !($this->table->forFunction($c->name)?->isDelegated() ?? false);
        }
        if ($node instanceof Expr\StaticCall) {
            if ($c->receiver !== null || $c->staticClass === null || !$this->isVendor($c->staticClass, $ctx)) {
                return false;
            }

            return !($this->table->forStatic($c->staticClass, $c->name)?->isDelegated() ?? false);
        }
        if ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) {
            if (!$c->receiver instanceof ObjectT || !$this->isVendor($c->receiver->class, $ctx)) {
                return false;
            }

            return !($this->table->forMethod($c->receiver->class, $c->name)?->isDelegated() ?? false);
        }

        return false;
    }

    public function handle(CallSite $c, EvalContext $ctx): Type
    {
        $types = [];
        $unpacked = false;
        foreach ($c->args as $i => $arg) {
            $types[$i] = $c->argType($i);
            $unpacked = $unpacked || $arg->unpack;
        }

        [$what, $entry, $refusal] = $this->lookup($c);
        $at = $c->node;
        if ($refusal !== null) {
            return $ctx->gap(GapCode::VENDOR_CALL_NOT_MODELLED, $at, "{$what} is refused by the allow-list: {$refusal}");
        }
        if ($entry === null) {
            return $ctx->gap(GapCode::VENDOR_CALL_NOT_MODELLED, $at, "{$what} is not on the allow-list, so what it does is not known");
        }
        if ($entry->isDelegated()) {
            return $ctx->gap(GapCode::VENDOR_CALL_NOT_MODELLED, $at, "{$what} belongs to {$entry->delegate}, which has not taken it");
        }
        if ($unpacked) {
            return $ctx->gap(GapCode::VENDOR_CALL_NOT_MODELLED, $at, "{$what} is given unpacked arguments, so its arity is not known");
        }
        $count = count($c->args);
        if ($count < $entry->argsMin || ($entry->argsMax !== null && $count > $entry->argsMax)) {
            $range = $entry->argsMax === null ? "at least {$entry->argsMin}" : ($entry->argsMin === $entry->argsMax ? (string) $entry->argsMin : "{$entry->argsMin} to {$entry->argsMax}");

            return $ctx->gap(GapCode::VENDOR_CALL_NOT_MODELLED, $at, "{$what} is called with {$count} argument(s); the allow-list covers {$range}");
        }
        foreach ($types as $type) {
            $risky = self::callsBack($type, $entry->storesClosures);
            if ($risky !== null) {
                return $ctx->gap(GapCode::VENDOR_CALL_NOT_MODELLED, $at, "{$what} takes {$risky} argument it could call back into");
            }
        }

        return $this->answer($entry, $c, $types);
    }

    /**
     * @return array{0: string, 1: KnownEntry|null, 2: string|null} what to call it, its entry, the reason it is refused
     */
    private function lookup(CallSite $c): array
    {
        $node = $c->node;
        if ($node instanceof Expr\FuncCall) {
            return ["{$c->name}()", $this->table->forFunction($c->name), $this->table->refusedFunction($c->name)];
        }
        if ($node instanceof Expr\StaticCall && $c->staticClass !== null) {
            return [
                "{$c->staticClass}::{$c->name}()",
                $this->table->forStatic($c->staticClass, $c->name),
                $this->table->refusedMethod($c->staticClass, $c->name),
            ];
        }
        if ($c->receiver instanceof ObjectT) {
            return [
                "{$c->receiver->class}->{$c->name}()",
                $this->table->forMethod($c->receiver->class, $c->name),
                $this->table->refusedMethod($c->receiver->class, $c->name),
            ];
        }

        return ["{$c->name}()", null, null];
    }

    /** @param array<int, Type> $types the argument types by position */
    private function answer(KnownEntry $entry, CallSite $c, array $types): Type
    {
        $returns = (string) $entry->returns;
        if (str_starts_with($returns, 'object:')) {
            return new ObjectT(substr($returns, strlen('object:')));
        }
        if (preg_match('/^arg:(\d+)(?::(\w+))?$/', $returns, $m) === 1) {
            return $types[$this->argumentIndex($c, (int) $m[1], $m[2] ?? null)] ?? new PlainT();
        }

        return new PlainT();
    }

    /** The argument given by name, else the unnamed one at that position; null when the call leaves it out. */
    private function argumentIndex(CallSite $c, int $position, ?string $name): ?int
    {
        if ($name !== null) {
            foreach ($c->args as $i => $arg) {
                if ($arg->name?->toString() === $name) {
                    return $i;
                }
            }
        }
        $arg = $c->args[$position] ?? null;

        return $arg !== null && $arg->name === null ? $position : null;
    }

    /** True for a class the app does not define and does not bind to a class of its own. */
    private function isVendor(string $class, EvalContext $ctx): bool
    {
        try {
            if ($ctx->source()->findClass($class) !== null) {
                return false;
            }
        } catch (ParseFailed) {
            return false;
        }

        return $ctx->bindings()->resolve($class)->kind === BindingResultKind::None;
    }

    /** What kind of argument the call could call back into, or null. UnknownT already carries its own gap. */
    private static function callsBack(Type $type, bool $closuresAreStored): ?string
    {
        if ($type instanceof PlainT || $type instanceof ModelT || $type instanceof ListT || $type instanceof ShapeT || $type instanceof UnknownT) {
            return null;
        }
        if ($type instanceof ClosureT) {
            return $closuresAreStored ? null : 'a closure';
        }
        if ($type instanceof UnionT) {
            foreach ($type->members as $member) {
                $risky = self::callsBack($member, $closuresAreStored);
                if ($risky !== null) {
                    return $risky;
                }
            }

            return null;
        }
        if ($type instanceof ObjectT) {
            return 'an object';
        }
        if ($type instanceof BuilderT) {
            return 'a query builder';
        }

        return 'an unlisted kind of';
    }
}
