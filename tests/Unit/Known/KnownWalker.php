<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Known;

use PeakSim\Analysis\Contracts\CallHandler;
use PeakSim\Analysis\Contracts\CallSite;
use PeakSim\Analysis\Type\ClosureT;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Model\Cardinality;
use PeakSim\Model\Frame;
use PeakSim\Model\GapCode;
use PeakSim\Model\Origin;
use PeakSim\Model\Site;
use PeakSim\Source\AppSourceIndex;
use PeakSim\Tests\Support\FakeEvalContext;
use PeakSim\Tests\Support\RecordingContext;
use PeakSim\Tests\Unit\Engine\Support\FakeBindingTable;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\ParserFactory;

/**
 * A few lines of "engine" for handler tests: it evaluates one expression, turns each call into a CallSite and
 * gives it to the first handler that handles() it. A call nobody takes is a gap, as in the real engine
 * (UNRESOLVED_CALL), and is also listed in $unhandled so a test can tell "declined" from "handled".
 * Names written as `Cache::` or `Gate::` are expanded through ALIASES, as a `use` line would.
 */
final class KnownWalker
{
    public const FILE = 'app/Http/Controllers/TestController.php';

    private const FACADES = 'Illuminate\\Support\\Facades\\';

    public const ALIASES = [
        'Cache' => self::FACADES . 'Cache',
        'GlobalCache' => 'Cache',
        'Gate' => self::FACADES . 'Gate',
        'Validator' => self::FACADES . 'Validator',
        'Event' => self::FACADES . 'Event',
        'Bus' => self::FACADES . 'Bus',
        'Queue' => self::FACADES . 'Queue',
        'Auth' => self::FACADES . 'Auth',
        'Mail' => self::FACADES . 'Mail',
        'Notification' => self::FACADES . 'Notification',
        'Http' => self::FACADES . 'Http',
        'DB' => self::FACADES . 'DB',
        'App' => self::FACADES . 'App',
        'Storage' => self::FACADES . 'Storage',
        'Attribute' => 'Illuminate\\Database\\Eloquent\\Casts\\Attribute',
        'Order' => 'App\\Models\\Order',
        'Customer' => 'App\\Models\\Customer',
        'Report' => 'App\\Services\\Report',
    ];

    public readonly FakeEvalContext $fake;
    public readonly RecordingContext $ctx;

    /** @var list<string> calls no handler took ("Class::name" or "name()") */
    public array $unhandled = [];

    /** @var int how many closures were run */
    public int $closuresRun = 0;

    /**
     * @param list<CallHandler> $handlers
     * @param array<string, Type> $vars
     * @param array<string, list<string>> $bound abstract => implementations the app binds
     */
    public function __construct(
        private readonly array $handlers,
        public array $vars = [],
        array $bound = [],
        string $file = self::FILE,
    ) {
        $this->fake = new FakeEvalContext(
            fn (Expr $e): Type => $this->eval($e),
            fn (ClosureT $c, array $args): Type => $this->invoke($c, $args),
            null,
            null,
            new AppSourceIndex(__DIR__ . '/App'),
            new FakeBindingTable($bound),
            $file,
        );
        $this->ctx = new RecordingContext($this->fake);
    }

    public function parse(string $php): Expr
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $first = ($parser->parse('<?php ' . $php . ';') ?? [])[0] ?? null;
        if (!$first instanceof Node\Stmt\Expression) {
            throw new \InvalidArgumentException('not an expression: ' . $php);
        }

        return $first->expr;
    }

    public function run(string $php): Type
    {
        return $this->eval($this->parse($php));
    }

    public function eval(Expr $e): Type
    {
        return $this->ctx->audit($this->evalNode($e), 'walker line ' . $e->getStartLine());
    }

    private function evalNode(Expr $e): Type
    {
        switch (true) {
            case $e instanceof Expr\Variable:
                return is_string($e->name) ? ($this->vars[$e->name] ?? new PlainT()) : new PlainT();
            case $e instanceof Expr\Closure:
            case $e instanceof Expr\ArrowFunction:
                return new ClosureT($e, $this->vars, self::FILE);
            case $e instanceof Expr\Array_:
                foreach ($e->items as $item) {
                    $this->eval($item->value);
                }

                return new ListT(new PlainT(), Cardinality::bounded());
            case $e instanceof Expr\BinaryOp:
                $this->eval($e->left);
                $this->eval($e->right);

                return new PlainT();
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
            case $e instanceof Expr\FuncCall && $e->name instanceof Node\Name:
                return $this->dispatch($e, null, null, ltrim($e->name->toString(), '\\'), $e->args);
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
        $this->unhandled[] = $staticClass !== null ? $staticClass . '::' . $name : ($receiver !== null ? $receiver->fingerprint() . '->' . $name : $name . '()');

        return $this->ctx->gap(GapCode::UNRESOLVED_CALL, $node, 'no handler took this call');
    }

    /** @param list<Type> $args */
    private function invoke(ClosureT $closure, array $args): Type
    {
        $saved = $this->vars;
        $this->closuresRun++;
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
        return self::ALIASES[$name] ?? ltrim($name, '\\');
    }

    public static function frame(string $table, bool $streamed = false): Frame
    {
        return new Frame(new Origin($table), $streamed, new Site(self::FILE, 1));
    }
}
