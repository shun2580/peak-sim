<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Blade;

use PeakSim\Analysis\Contracts\CallSite;
use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\ShapeT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Analysis\Type\UnknownT;
use PeakSim\Model\CardinalityKind;
use PeakSim\Model\GapCode;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;

/**
 * The variables a view call hands to a template: an array literal, compact(), or an array the engine already
 * knows the shape of. Each value is evaluated exactly once, here, so its events are not repeated at render time.
 * Anything that cannot be read as a name-to-type map is a gap, never an empty map taken as "no variables".
 */
final class ViewHandlerData
{
    /** @return array<string, Type> */
    public function read(?Expr $e, EvalContext $ctx): array
    {
        if ($e === null) {
            return [];
        }
        if ($e instanceof Expr\Array_) {
            return $this->fromArray($e, $ctx);
        }
        if ($e instanceof Expr\FuncCall && $e->name instanceof Name && strtolower($e->name->toString()) === 'compact') {
            return $this->fromCompact($e, $ctx);
        }
        $type = $ctx->evaluate($e);
        if ($type instanceof ShapeT) {
            return $this->named($type->entries);
        }
        if ($type instanceof UnknownT || $this->isEmptyList($type)) {
            return [];
        }
        $ctx->gap(GapCode::UNRESOLVED_CALL, $e, 'the data of the view is not an array literal, compact() or an array of known keys: its variables are not known');

        return [];
    }

    /**
     * The data of a `->with()` call: ('key', value), (['key' => value]) or (compact(...)).
     *
     * @return array<string, Type>
     */
    public function readWith(CallSite $c, EvalContext $ctx): array
    {
        $first = $c->argNode(0);
        $second = $c->argNode(1);
        if ($second === null) {
            return $this->read($first, $ctx);
        }
        $value = $ctx->evaluate($second);
        if ($first instanceof Scalar\String_) {
            return $this->named([$first->value => $value]);
        }
        if ($first !== null) {
            $ctx->evaluate($first);
            $ctx->gap(GapCode::UNRESOLVED_CALL, $first, 'the name of a variable passed with with() is not a literal string: the variable is not known');
        }

        return [];
    }

    /** @return array<string, Type> */
    private function fromArray(Expr\Array_ $array, EvalContext $ctx): array
    {
        $out = [];
        foreach ($array->items as $item) {
            if ($item === null) {
                continue;
            }
            if ($item->unpack) {
                $type = $ctx->evaluate($item->value);
                if ($type instanceof ShapeT) {
                    $out = [...$out, ...$this->named($type->entries)];
                } elseif (!$type instanceof UnknownT) {
                    $ctx->gap(GapCode::UNRESOLVED_CALL, $item->value, 'an array spread into the data of a view has keys that are not known');
                }
                continue;
            }
            if ($item->key !== null && !$item->key instanceof Scalar\String_) {
                $ctx->evaluate($item->key);
            }
            $type = $ctx->evaluate($item->value);
            if ($item->key instanceof Scalar\String_) {
                $out = [...$out, ...$this->named([$item->key->value => $type])];
            } else {
                $ctx->gap(GapCode::UNRESOLVED_CALL, $item->value, 'a value in the data of a view has no literal name: the variable is not known');
            }
        }

        return $out;
    }

    /** @return array<string, Type> */
    private function fromCompact(Expr\FuncCall $call, EvalContext $ctx): array
    {
        $out = [];
        foreach ($call->args as $arg) {
            $names = $arg->value instanceof Expr\Array_ ? array_map(static fn ($i) => $i?->value, $arg->value->items) : [$arg->value];
            foreach ($names as $name) {
                if (!$name instanceof Scalar\String_) {
                    $ctx->gap(GapCode::UNRESOLVED_CALL, $arg->value, 'compact() is given a name that is not a literal string: the variables it passes are not known');
                    continue;
                }
                if ($this->isVariableName($name->value)) {
                    $out[$name->value] = $ctx->evaluate(new Expr\Variable($name->value, $call->getAttributes()));
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, Type> $entries
     * @return array<string, Type> only the keys a template can use as variables
     */
    private function named(array $entries): array
    {
        return array_filter($entries, fn (string|int $key): bool => is_string($key) && $this->isVariableName($key), ARRAY_FILTER_USE_KEY);
    }

    private function isVariableName(string $name): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1 && $name !== 'this';
    }

    private function isEmptyList(Type $type): bool
    {
        return $type instanceof ListT && $type->card->kind === CardinalityKind::Bounded && $type->elem instanceof PlainT;
    }
}
