<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Query;

use PeakSim\Analysis\Contracts\CallHandler;
use PeakSim\Analysis\Contracts\CallSite;
use PeakSim\Analysis\Type\ClosureT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\ShapeT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Analysis\Type\UnknownT;
use PeakSim\Model\Frame;
use PeakSim\Model\Origin;
use PeakSim\Model\Site;
use PeakSim\Source\AppModelCatalog;
use PeakSim\Source\AppSourceIndex;
use PeakSim\Tests\Support\FakeEvalContext;
use PeakSim\Tests\Support\RecordingContext;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\ParserFactory;

/**
 * A few lines of "engine" for handler tests: it evaluates one expression, turns each call into a CallSite and
 * gives it to the first handler that handles() it. Closures are run with their parameters bound; variables come
 * from a map. It stands in for the real engine (written by someone else) and knows nothing more than the contract.
 */
final class QueryWalker
{
    private const NAMESPACE = 'App\\Models\\';

    public readonly FakeEvalContext $fake;
    public readonly RecordingContext $ctx;

    /** @var list<array{0: string, 1: string, 2: list<Type>}> */
    public array $methodCalls = [];

    /** @var array<string, Type> "Class::method" => what callMethod returns (default PlainT) */
    public array $methodResults = [];

    /** @var list<Type> what the closures under test passed to __probe() */
    public array $probes = [];

    /** @var list<string> calls no handler took */
    public array $unhandled = [];

    /**
     * @param list<CallHandler> $handlers
     * @param array<string, Type> $vars
     */
    public function __construct(
        AppSourceIndex $source,
        AppModelCatalog $models,
        private readonly array $handlers,
        public array $vars = [],
    ) {
        $this->fake = new FakeEvalContext(
            fn (Expr $e): Type => $this->eval($e),
            fn (ClosureT $c, array $args): Type => $this->invoke($c, $args),
            function (string $class, string $method, array $args): Type {
                $this->methodCalls[] = [$class, $method, $args];

                return $this->methodResults[$class . '::' . $method] ?? new PlainT();
            },
            $models,
            $source,
            null,
            'app/Http/Controllers/TestController.php',
        );
        $this->ctx = new RecordingContext($this->fake);
    }

    public function expression(string $php): Expr
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse('<?php ' . $php . ';') ?? [];
        $first = $stmts[0] ?? null;
        if (!$first instanceof Node\Stmt\Expression) {
            throw new \InvalidArgumentException('not an expression: ' . $php);
        }

        return $first->expr;
    }

    public function run(string $php): Type
    {
        return $this->eval($this->expression($php));
    }

    public function eval(Expr $e): Type
    {
        $type = $this->evalNode($e);

        return $this->ctx->audit($type, 'walker line ' . $e->getStartLine());
    }

    private function evalNode(Expr $e): Type
    {
        switch (true) {
            case $e instanceof Expr\Variable:
                return is_string($e->name) ? ($this->vars[$e->name] ?? new PlainT()) : new PlainT();
            case $e instanceof Expr\Closure:
            case $e instanceof Expr\ArrowFunction:
                return new ClosureT($e, $this->vars, 'app/Http/Controllers/TestController.php');
            case $e instanceof Expr\Array_:
                $entries = [];
                foreach ($e->items as $i => $item) {
                    $key = $item->key instanceof Scalar\String_ ? $item->key->value : (string) $i;
                    $entries[$key] = $this->eval($item->value);
                }

                return new ShapeT($entries);
            case $e instanceof Expr\StaticCall:
                $class = $e->class instanceof Node\Name ? $this->fqcn($e->class->toString()) : null;
                $name = $e->name instanceof Node\Identifier ? $e->name->toString() : '';

                return $this->dispatch($e, null, $class, $name, $e->args);
            case $e instanceof Expr\MethodCall:
                $receiver = $this->eval($e->var);
                $name = $e->name instanceof Node\Identifier ? $e->name->toString() : '';

                return $this->dispatch($e, $receiver, null, $name, $e->args);
            case $e instanceof Expr\PropertyFetch:
                $receiver = $this->eval($e->var);
                $name = $e->name instanceof Node\Identifier ? $e->name->toString() : '';

                return $this->dispatch($e, $receiver, null, $name, []);
            case $e instanceof Expr\FuncCall && $e->name instanceof Node\Name && $e->name->toString() === '__probe':
                $this->probes[] = isset($e->args[0]) && $e->args[0] instanceof Node\Arg ? $this->eval($e->args[0]->value) : new PlainT();

                return new PlainT();
            case $e instanceof Expr\New_:
                $class = $e->class instanceof Node\Name ? $this->fqcn($e->class->toString()) : null;

                return $this->dispatch($e, null, $class, '__construct', $e->args);
            default:
                return new PlainT();
        }
    }

    /** @param array<array-key, Node\Arg|Node\VariadicPlaceholder> $args */
    private function dispatch(Expr $node, ?Type $receiver, ?string $staticClass, string $name, array $args): Type
    {
        $args = array_values(array_filter($args, static fn ($a): bool => $a instanceof Node\Arg));
        $call = new CallSite($node, $receiver, $staticClass, $name, $args, fn (int $i): Type => $this->eval($args[$i]->value));
        foreach ($this->handlers as $handler) {
            if ($handler->handles($call, $this->ctx)) {
                return $this->ctx->handle($handler, $call);
            }
        }
        $this->unhandled[] = ($staticClass ?? $receiver?->fingerprint() ?? '?') . '::' . $name;

        return new PlainT();
    }

    /** @param list<Type> $args */
    private function invoke(ClosureT $closure, array $args): Type
    {
        $saved = $this->vars;
        try {
            $node = $closure->node;
            $this->vars = $closure->captured;
            foreach ($node->params as $i => $param) {
                if ($param->var instanceof Expr\Variable && is_string($param->var->name)) {
                    $this->vars[$param->var->name] = $args[$i] ?? new PlainT();
                }
            }
            if ($node instanceof Expr\ArrowFunction) {
                return $this->eval($node->expr);
            }
            $result = new PlainT();
            foreach ($node->stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\Return_ && $stmt->expr !== null) {
                    $result = $this->eval($stmt->expr);
                } elseif ($stmt instanceof Node\Stmt\Expression) {
                    $this->eval($stmt->expr);
                }
            }

            return $result;
        } finally {
            $this->vars = $saved;
        }
    }

    private function fqcn(string $name): string
    {
        return match ($name) {
            'DB' => 'Illuminate\\Support\\Facades\\DB',
            default => str_contains($name, '\\') ? ltrim($name, '\\') : self::NAMESPACE . $name,
        };
    }

    public static function frame(string $table, bool $streamed = false, ?string $per = null): Frame
    {
        return new Frame(new Origin($table, $per), $streamed, new Site('app/Http/Controllers/TestController.php', 1));
    }
}
