<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Collection\Support;

use PeakSim\Analysis\Contracts\CallSite;
use PeakSim\Analysis\Type\ClosureT;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Model\Cardinality;
use PhpParser\Node;
use PhpParser\ParserFactory;

/**
 * Builds the CallSite a handler gets, from a snippet of PHP: Calls::method($list, "map(fn (\$o) => \$o->x)").
 * Closures become ClosureT, literals PlainT; anything else (a variable) must be given in $argTypes by position.
 */
final class Calls
{
    public const FILE = 'app/T.php';

    /** @param array<int, Type> $argTypes types for arguments that are not closures or literals, by position */
    public static function method(Type $receiver, string $call, array $argTypes = []): CallSite
    {
        $node = self::parse('$x->' . $call . ';');
        assert($node instanceof Node\Expr\MethodCall);

        return self::site($node, $receiver, $node->name instanceof Node\Identifier ? $node->name->toString() : '?', $node->args, $argTypes);
    }

    /** @param array<int, Type> $argTypes */
    public static function func(string $call, array $argTypes = []): CallSite
    {
        $node = self::parse($call . ';');
        assert($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name);

        return self::site($node, null, $node->name->toString(), $node->args, $argTypes);
    }

    public static function property(Type $receiver, string $name): CallSite
    {
        $node = self::parse('$x->' . $name . ';');
        assert($node instanceof Node\Expr\PropertyFetch);

        return new CallSite($node, $receiver, null, $name, []);
    }

    public static function parse(string $code): Node\Expr
    {
        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse("<?php\n" . $code);
        assert($ast !== null && $ast[0] instanceof Node\Stmt\Expression);

        return $ast[0]->expr;
    }

    /**
     * @param array<Node\Arg|Node\VariadicPlaceholder> $rawArgs
     * @param array<int, Type> $given
     */
    private static function site(Node $node, ?Type $receiver, string $name, array $rawArgs, array $given): CallSite
    {
        $args = array_values(array_filter($rawArgs, static fn (Node $a): bool => $a instanceof Node\Arg));
        $eval = static function (int $i) use ($args, $given): Type {
            if (isset($given[$i])) {
                return $given[$i];
            }
            $value = $args[$i]->value;

            return match (true) {
                $value instanceof Node\Expr\Closure, $value instanceof Node\Expr\ArrowFunction => new ClosureT($value, [], self::FILE),
                $value instanceof Node\Scalar, $value instanceof Node\Expr\ConstFetch, $value instanceof Node\Expr\ClassConstFetch => new PlainT(),
                $value instanceof Node\Expr\Array_ => new ListT(new PlainT(), Cardinality::bounded()),
                default => throw new \LogicException('give the type of argument #' . $i . ' in $argTypes'),
            };
        };

        return new CallSite($node, $receiver, null, $name, $args, $eval);
    }
}
