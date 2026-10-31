<?php

declare(strict_types=1);

namespace PeakSim\Source;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Scalar;

/**
 * Evaluates the small subset of PHP that class constants are made of: literals, class constants,
 * arrays of those, and plain arithmetic. Anything else is NotConstant: a value that cannot be
 * proven constant is never guessed.
 */
final class ConstantEvaluator
{
    private const MAX_DEPTH = 50;

    /** Core constants that do not depend on the app. */
    private const GLOBAL_CONSTANTS = [
        'PHP_INT_MAX' => PHP_INT_MAX,
        'PHP_INT_MIN' => PHP_INT_MIN,
        'PHP_INT_SIZE' => PHP_INT_SIZE,
        'PHP_EOL' => PHP_EOL,
    ];

    /** @var array<string, ConstValue> */
    private array $done = [];

    /** @var array<string, true> constants being evaluated right now (cycle guard) */
    private array $active = [];

    public function __construct(private readonly AppSourceIndex $index)
    {
    }

    public function classConstant(string $fqcn, string $name): ConstValue
    {
        return $this->lookup($fqcn, $name, 0);
    }

    public function evaluate(Expr $e, ?string $classContext): ConstValue
    {
        return $this->eval($e, $classContext, 0);
    }

    private function lookup(string $fqcn, string $name, int $depth): ConstValue
    {
        $key = $fqcn . '::' . $name;
        if (isset($this->done[$key])) {
            return $this->done[$key];
        }
        if (isset($this->active[$key]) || $depth >= self::MAX_DEPTH) {
            return ConstValue::notConstant();
        }
        $this->active[$key] = true;
        try {
            $result = $this->lookupUncached($fqcn, $name, $depth);
        } finally {
            unset($this->active[$key]);
        }

        return $this->done[$key] = $result;
    }

    private function lookupUncached(string $fqcn, string $name, int $depth): ConstValue
    {
        try {
            $info = $this->index->findClass($fqcn);
        } catch (ParseFailed) {
            return ConstValue::notConstant();
        }
        if ($info === null) {
            return ConstValue::notConstant();
        }
        foreach ($info->node->getConstants() as $declaration) {
            foreach ($declaration->consts as $const) {
                if ($const->name->toString() === $name) {
                    // self:: inside the value means the class that declares the constant
                    return $this->eval($const->value, $info->fqcn, $depth + 1);
                }
            }
        }
        $ancestors = array_filter([$info->parent, ...$info->interfaces]);
        foreach ($ancestors as $ancestor) {
            $found = $this->lookup($ancestor, $name, $depth + 1);
            if ($found->known) {
                return $found;
            }
        }

        return ConstValue::notConstant();
    }

    private function eval(Expr $e, ?string $context, int $depth): ConstValue
    {
        if ($depth >= self::MAX_DEPTH) {
            return ConstValue::notConstant();
        }
        $no = ConstValue::notConstant();

        switch (true) {
            case $e instanceof Scalar\Int_:
            case $e instanceof Scalar\Float_:
            case $e instanceof Scalar\String_:
                return ConstValue::known($e->value);

            case $e instanceof Expr\ConstFetch:
                return $this->constFetch($e->name);

            case $e instanceof Expr\ClassConstFetch:
                return $this->classConstFetch($e, $context, $depth);

            case $e instanceof Expr\UnaryMinus:
            case $e instanceof Expr\UnaryPlus:
                $operand = $this->eval($e->expr, $context, $depth + 1);
                if (!$operand->known || !(is_int($operand->value) || is_float($operand->value))) {
                    return $no;
                }

                return ConstValue::known($e instanceof Expr\UnaryMinus ? -$operand->value : +$operand->value);

            case $e instanceof BinaryOp:
                return $this->binary($e, $context, $depth);

            case $e instanceof Expr\Array_:
                return $this->array($e, $context, $depth);

            case $e instanceof Expr\ArrayDimFetch:
                if ($e->dim === null) {
                    return $no;
                }
                $base = $this->eval($e->var, $context, $depth + 1);
                $key = $this->eval($e->dim, $context, $depth + 1);
                if (!$base->known || !$key->known || !is_array($base->value) || !(is_int($key->value) || is_string($key->value))) {
                    return $no;
                }

                return array_key_exists($key->value, $base->value) ? ConstValue::known($base->value[$key->value]) : $no;

            default:
                return $no;
        }
    }

    private function constFetch(Node\Name $name): ConstValue
    {
        // only a bare name can be a global constant; `Some\NAME` is the app's own
        if (count($name->getParts()) !== 1) {
            return ConstValue::notConstant();
        }
        $plain = $name->getLast();
        switch (strtolower($plain)) {
            case 'true':
                return ConstValue::known(true);
            case 'false':
                return ConstValue::known(false);
            case 'null':
                return ConstValue::known(null);
        }

        return array_key_exists($plain, self::GLOBAL_CONSTANTS)
            ? ConstValue::known(self::GLOBAL_CONSTANTS[$plain])
            : ConstValue::notConstant();
    }

    private function classConstFetch(Expr\ClassConstFetch $e, ?string $context, int $depth): ConstValue
    {
        $no = ConstValue::notConstant();
        if (!$e->class instanceof Node\Name || !$e->name instanceof Node\Identifier) {
            return $no;
        }
        $target = match (strtolower($e->class->toString())) {
            'self' => $context,
            'static' => null,
            'parent' => $this->parentOf($context),
            default => $e->class->toString(),
        };
        if ($target === null) {
            return $no;
        }
        if (strtolower($e->name->toString()) === 'class') {
            return ConstValue::known($target);
        }

        return $this->lookup($target, $e->name->toString(), $depth + 1);
    }

    private function parentOf(?string $class): ?string
    {
        if ($class === null) {
            return null;
        }
        try {
            return $this->index->findClass($class)?->parent;
        } catch (ParseFailed) {
            return null;
        }
    }

    private function binary(BinaryOp $e, ?string $context, int $depth): ConstValue
    {
        $no = ConstValue::notConstant();
        $left = $this->eval($e->left, $context, $depth + 1);
        $right = $this->eval($e->right, $context, $depth + 1);
        if (!$left->known || !$right->known) {
            return $no;
        }
        [$a, $b] = [$left->value, $right->value];

        if ($e instanceof BinaryOp\Concat) {
            return $this->isStringable($a) && $this->isStringable($b) ? ConstValue::known($this->text($a) . $this->text($b)) : $no;
        }
        if ($e instanceof BinaryOp\Plus && is_array($a) && is_array($b)) {
            return ConstValue::known($a + $b);
        }
        if (!(is_int($a) || is_float($a)) || !(is_int($b) || is_float($b))) {
            return $no;
        }

        $integersOnly = $e instanceof BinaryOp\Mod || $e instanceof BinaryOp\ShiftLeft || $e instanceof BinaryOp\ShiftRight
            || $e instanceof BinaryOp\BitwiseAnd || $e instanceof BinaryOp\BitwiseOr || $e instanceof BinaryOp\BitwiseXor;
        if ($integersOnly && !(is_int($a) && is_int($b))) {
            return $no;
        }

        try {
            return match (true) {
                $e instanceof BinaryOp\Plus => ConstValue::known($a + $b),
                $e instanceof BinaryOp\Minus => ConstValue::known($a - $b),
                $e instanceof BinaryOp\Mul => ConstValue::known($a * $b),
                $e instanceof BinaryOp\Div => ConstValue::known($a / $b),
                $e instanceof BinaryOp\Mod => ConstValue::known($a % $b),
                $e instanceof BinaryOp\Pow => ConstValue::known($a ** $b),
                $e instanceof BinaryOp\ShiftLeft => ConstValue::known($a << $b),
                $e instanceof BinaryOp\ShiftRight => ConstValue::known($a >> $b),
                $e instanceof BinaryOp\BitwiseAnd => ConstValue::known($a & $b),
                $e instanceof BinaryOp\BitwiseOr => ConstValue::known($a | $b),
                $e instanceof BinaryOp\BitwiseXor => ConstValue::known($a ^ $b),
                default => $no,
            };
        } catch (\ArithmeticError | \TypeError) {
            // DivisionByZeroError is an ArithmeticError
            return $no;
        }
    }

    private function array(Expr\Array_ $e, ?string $context, int $depth): ConstValue
    {
        $no = ConstValue::notConstant();
        $out = [];
        foreach ($e->items as $item) {
            if ($item->byRef || $item->unpack) {
                return $no;
            }
            $value = $this->eval($item->value, $context, $depth + 1);
            if (!$value->known) {
                return $no;
            }
            if ($item->key === null) {
                $out[] = $value->value;
                continue;
            }
            $key = $this->eval($item->key, $context, $depth + 1);
            if (!$key->known || !(is_int($key->value) || is_string($key->value))) {
                return $no;
            }
            $out[$key->value] = $value->value;
        }

        return ConstValue::known($out);
    }

    private function isStringable(mixed $v): bool
    {
        return is_scalar($v) || $v === null;
    }

    private function text(mixed $v): string
    {
        return is_bool($v) ? ($v ? '1' : '') : (string) $v;
    }
}
