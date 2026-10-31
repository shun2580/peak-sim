<?php

declare(strict_types=1);

namespace PeakSim\Tests\Acceptance\Support;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Rewrites the source of a copy of an app so that a call can no longer be followed (gap injection, design 3.2-2).
 *
 * It reads the app with php-parser; it is not the tool and shares no code with it. Three edits:
 *  - atMethodStart:    put `\vendor_unknown_fn();` as the first statement of a method;
 *  - replaceAppCall:   replace the first outermost call into the app (`Order::get()`, `$this->service->build()`,
 *                      `$injected->all()`) of a method by `\vendor_unknown_fn()`;
 *  - replaceDeepestCall: follow calls into the app as far as they can be resolved by name (properties typed by a
 *                      constructor, interfaces to their implementations) and replace a call in the deepest method.
 */
final class SourceMutator
{
    public const UNKNOWN_CALL = '\\vendor_unknown_fn()';

    /** @var array<string, array{file: string, source: string, node: Stmt\ClassLike}> */
    private array $classes = [];

    public function __construct(private readonly string $appRoot)
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        foreach (self::phpFiles($appRoot . '/app') as $file) {
            $source = (string) file_get_contents($file);
            $stmts = $parser->parse($source) ?? [];
            $stmts = (new NodeTraverser(new NameResolver()))->traverse($stmts);
            foreach ((new NodeFinder())->findInstanceOf($stmts, Stmt\ClassLike::class) as $class) {
                if ($class->namespacedName !== null) {
                    $this->classes[$class->namespacedName->toString()] = [
                        'file' => substr($file, strlen($appRoot) + 1),
                        'source' => $source,
                        'node' => $class,
                    ];
                }
            }
        }
    }

    /** "Class@method" or "Class" (an invokable controller) as [class, method]. @return array{0: string, 1: string} */
    public static function splitAction(string $action): array
    {
        return str_contains($action, '@') ? explode('@', $action, 2) : [$action, '__invoke'];
    }

    public function fileOf(string $class): ?string
    {
        return $this->classes[$class]['file'] ?? null;
    }

    /** @return array{file: string, source: string}|null the file to write and its new content, or null if the method is not found */
    public function atMethodStart(string $class, string $method, string $code): ?array
    {
        $m = $this->method($class, $method);
        if ($m === null) {
            return null;
        }
        $source = $this->classes[$class]['source'];
        if ($m->stmts !== null && $m->stmts !== []) {
            $at = $m->stmts[0]->getStartFilePos();
            $indent = str_repeat(' ', max(0, $m->stmts[0]->getStartLine() === $m->getStartLine() ? 0 : self::columnOf($source, $at)));
            $new = substr($source, 0, $at) . $code . "\n" . $indent . substr($source, $at);
        } else {
            $at = $m->getEndFilePos();     // the closing brace of an empty body
            $new = substr($source, 0, $at) . $code . "\n" . substr($source, $at);
        }

        return ['file' => $this->classes[$class]['file'], 'source' => $new];
    }

    /** @return array{file: string, source: string}|null null when the method has no call into the app to replace */
    public function replaceAppCall(string $class, string $method): ?array
    {
        $m = $this->method($class, $method);
        if ($m === null) {
            return null;
        }
        $calls = $this->outermostAppCalls($class, $m);

        return $calls === [] ? null : $this->replace($class, $calls[0]);
    }

    /**
     * @return array{file: string, source: string, depth: int}|null null unless a call below the action can be followed
     *         (depth 1 = the action itself; the result is only worth using at depth 2 or more)
     */
    public function replaceDeepestCall(string $class, string $method): ?array
    {
        $best = $this->deepest($class, $method, 1, [$class . '::' . $method]);
        if ($best === null) {
            return null;
        }
        $edit = $this->replace($best['class'], $best['node']);
        $edit['depth'] = $best['depth'];

        return $edit;
    }

    /** @return array{depth: int, class: string, node: Node}|null */
    private function deepest(string $class, string $method, int $depth, array $seen): ?array
    {
        $m = $this->method($class, $method);
        if ($m === null) {
            return null;
        }
        $calls = $this->outermostAppCalls($class, $m);
        $best = $calls === [] ? null : ['depth' => $depth, 'class' => $class, 'node' => $calls[0]];
        foreach ($calls as $call) {
            foreach ($this->targets($call, $class, $m) as [$targetClass, $targetMethod]) {
                $key = $targetClass . '::' . $targetMethod;
                if (in_array($key, $seen, true)) {
                    continue;
                }
                $found = $this->deepest($targetClass, $targetMethod, $depth + 1, [...$seen, $key]);
                if ($found !== null && ($best === null || $found['depth'] > $best['depth'])) {
                    $best = $found;
                }
            }
        }

        return $best;
    }

    /** @return list<array{0: string, 1: string}> [class, method] pairs a call may reach, when they are in the app */
    private function targets(Node $call, string $ctxClass, Stmt\ClassMethod $ctxMethod): array
    {
        $classes = [];
        $name = null;
        if ($call instanceof Expr\StaticCall && $call->class instanceof Name && $call->name instanceof Node\Identifier) {
            $classes = [$call->class->toString()];
            $name = $call->name->toString();
        } elseif ($call instanceof Expr\MethodCall && $call->name instanceof Node\Identifier) {
            $name = $call->name->toString();
            $var = $call->var;
            if ($var instanceof Expr\Variable && $var->name === 'this') {
                $classes = [$ctxClass];
            } elseif ($var instanceof Expr\PropertyFetch && $var->var instanceof Expr\Variable && $var->var->name === 'this' && $var->name instanceof Node\Identifier) {
                $classes = $this->implementationsOf($this->propertyType($ctxClass, $var->name->toString()));
            } elseif ($var instanceof Expr\Variable && is_string($var->name)) {
                $classes = $this->implementationsOf($this->paramType($ctxMethod, $var->name));
            }
        }
        $out = [];
        foreach ($classes as $c) {
            if ($name !== null && $this->method($c, $name) !== null) {
                $out[] = [$c, $name];
            }
        }

        return $out;
    }

    /** @return list<string> the class itself, or every app class implementing/extending it when it is an interface or abstract */
    private function implementationsOf(?string $type): array
    {
        if ($type === null || !isset($this->classes[$type])) {
            return [];
        }
        $node = $this->classes[$type]['node'];
        if ($node instanceof Stmt\Class_ && !$node->isAbstract()) {
            return [$type];
        }
        $found = [];
        foreach ($this->classes as $fqcn => $info) {
            $n = $info['node'];
            if (!$n instanceof Stmt\Class_ || $n->isAbstract()) {
                continue;
            }
            $parents = array_map(static fn (Name $x): string => $x->toString(), $n->implements);
            if ($n->extends !== null) {
                $parents[] = $n->extends->toString();
            }
            if (in_array($type, $parents, true)) {
                $found[] = $fqcn;
            }
        }
        sort($found);

        return $found;
    }

    private function propertyType(string $class, string $property): ?string
    {
        $node = $this->classes[$class]['node'] ?? null;
        if ($node === null) {
            return null;
        }
        $ctor = $node->getMethod('__construct');
        foreach ($ctor?->params ?? [] as $p) {
            if ($p->flags !== 0 && $p->var instanceof Expr\Variable && $p->var->name === $property && $p->type instanceof Name) {
                return $p->type->toString();
            }
        }
        foreach ($node->getProperties() as $prop) {
            foreach ($prop->props as $one) {
                if ($one->name->toString() === $property && $prop->type instanceof Name) {
                    return $prop->type->toString();
                }
            }
        }

        return null;
    }

    private function paramType(Stmt\ClassMethod $method, string $variable): ?string
    {
        foreach ($method->params as $p) {
            if ($p->var instanceof Expr\Variable && $p->var->name === $variable && $p->type instanceof Name) {
                return $p->type->toString();
            }
        }

        return null;
    }

    /**
     * The calls of a method body that go into the app, source order, none inside another.
     *
     * @return list<Node>
     */
    private function outermostAppCalls(string $class, Stmt\ClassMethod $m): array
    {
        $all = (new NodeFinder())->find($m->stmts ?? [], function (Node $n) use ($m): bool {
            if ($n instanceof Expr\StaticCall) {
                $c = $n->class;

                return $c instanceof Name && str_starts_with($c->toString(), 'App\\') && $n->name instanceof Node\Identifier;
            }
            if ($n instanceof Expr\MethodCall && $n->name instanceof Node\Identifier) {
                $v = $n->var;
                if ($v instanceof Expr\Variable && $v->name === 'this') {
                    return true;
                }
                if ($v instanceof Expr\PropertyFetch && $v->var instanceof Expr\Variable && $v->var->name === 'this') {
                    return true;
                }
                if ($v instanceof Expr\Variable && is_string($v->name)) {
                    $type = $this->paramType($m, $v->name);

                    return $type !== null && str_starts_with($type, 'App\\');
                }
            }

            return false;
        });
        $out = [];
        foreach ($all as $n) {
            $inside = false;
            foreach ($all as $other) {
                if ($other !== $n && $other->getStartFilePos() <= $n->getStartFilePos() && $other->getEndFilePos() >= $n->getEndFilePos()) {
                    $inside = true;
                    break;
                }
            }
            if (!$inside) {
                $out[] = $n;
            }
        }
        usort($out, static fn (Node $a, Node $b): int => $a->getStartFilePos() <=> $b->getStartFilePos());

        return $out;
    }

    /** @return array{file: string, source: string} */
    private function replace(string $class, Node $node): array
    {
        $source = $this->classes[$class]['source'];
        $start = $node->getStartFilePos();
        $end = $node->getEndFilePos();

        return [
            'file' => $this->classes[$class]['file'],
            'source' => substr($source, 0, $start) . self::UNKNOWN_CALL . substr($source, $end + 1),
        ];
    }

    private function method(string $class, string $method): ?Stmt\ClassMethod
    {
        $node = $this->classes[$class]['node'] ?? null;

        return $node?->getMethod($method);
    }

    private static function columnOf(string $source, int $pos): int
    {
        $lineStart = strrpos(substr($source, 0, $pos), "\n");

        return $pos - ($lineStart === false ? 0 : $lineStart + 1);
    }

    /** @return list<string> */
    private static function phpFiles(string $dir): array
    {
        $out = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                array_push($out, ...self::phpFiles($path));
            } elseif (str_ends_with($entry, '.php')) {
                $out[] = $path;
            }
        }
        sort($out);

        return $out;
    }
}
