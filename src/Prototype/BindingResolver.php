<?php

declare(strict_types=1);

namespace PeakSim\Prototype;

use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * Builds the interface => implementation table by reading, statically, the service providers listed in
 * bootstrap/providers.php for $this->app->bind() / singleton() / scoped() calls. Nothing is booted.
 */
final class BindingResolver
{
    private const BIND_METHODS = ['bind', 'singleton', 'scoped'];

    /** @var array<string, list<array{impl: string, file: string, line: int}>>|null */
    private ?array $bindings = null;

    public function __construct(private readonly ClassIndex $classes)
    {
    }

    /** @return list<array{impl: string, file: string, line: int}> every implementation bound to $abstract, in provider order */
    public function resolve(string $abstract): array
    {
        $this->bindings ??= $this->load();

        return $this->bindings[$abstract] ?? [];
    }

    /** @return array<string, list<array{impl: string, file: string, line: int}>> */
    private function load(): array
    {
        $bindings = [];
        $registry = $this->classes->appRoot() . '/bootstrap/providers.php';
        if (!is_file($registry)) {
            return $bindings;
        }
        $finder = new NodeFinder();
        /** @var list<Node\Expr\ClassConstFetch> $listed */
        $listed = $finder->findInstanceOf($this->classes->parseFile($registry), Node\Expr\ClassConstFetch::class);
        foreach ($listed as $entry) {
            $provider = $entry->class instanceof Node\Name ? $entry->class->toString() : null;
            $file = $provider === null ? null : $this->classes->fileFor($provider);
            if ($file === null) {
                continue; // a vendor provider: out of scope
            }
            /** @var list<Node\Expr\MethodCall> $calls */
            $calls = $finder->findInstanceOf($this->classes->parseFile($file), Node\Expr\MethodCall::class);
            foreach ($calls as $call) {
                if (!$this->isAppBind($call) || count($call->args) < 2) {
                    continue;
                }
                $abstract = $this->classConstant($call->args[0]);
                $impl = $this->classConstant($call->args[1]);
                if ($abstract !== null && $impl !== null) {
                    $bindings[$abstract][] = ['impl' => $impl, 'file' => $file, 'line' => $call->getStartLine()];
                }
            }
        }

        return $bindings;
    }

    private function isAppBind(Node\Expr\MethodCall $call): bool
    {
        return $call->name instanceof Node\Identifier
            && in_array($call->name->toString(), self::BIND_METHODS, true)
            && $call->var instanceof Node\Expr\PropertyFetch
            && $call->var->var instanceof Node\Expr\Variable
            && $call->var->var->name === 'this'
            && $call->var->name instanceof Node\Identifier
            && $call->var->name->toString() === 'app';
    }

    private function classConstant(Node\Arg|Node\VariadicPlaceholder $arg): ?string
    {
        if ($arg instanceof Node\Arg
            && $arg->value instanceof Node\Expr\ClassConstFetch
            && $arg->value->class instanceof Node\Name
            && $arg->value->name instanceof Node\Identifier
            && $arg->value->name->toString() === 'class'
        ) {
            return $arg->value->class->toString();
        }

        return null; // a closure or a string: not followed
    }
}
