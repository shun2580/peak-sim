<?php

declare(strict_types=1);

namespace PeakSim\Prototype;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Finds app classes by the PSR-4 map of the app's composer.json and parses them with php-parser (names resolved). */
final class ClassIndex
{
    /** @var array<string, string> namespace prefix => directory, longest prefix first */
    private array $psr4 = [];

    /** @var array<string, list<Node\Stmt>> */
    private array $parsed = [];

    private readonly \PhpParser\Parser $parser;

    public function __construct(private readonly string $appRoot)
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $composer = json_decode((string) file_get_contents($appRoot . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($composer['autoload']['psr-4'] ?? [] as $prefix => $dir) {
            $this->psr4[(string) $prefix] = $appRoot . '/' . trim((string) $dir, '/');
        }
        uksort($this->psr4, static fn (string $a, string $b): int => strlen($b) <=> strlen($a) ?: strcmp($a, $b));
    }

    public function appRoot(): string
    {
        return $this->appRoot;
    }

    public function relative(string $file): string
    {
        return str_starts_with($file, $this->appRoot . '/') ? substr($file, strlen($this->appRoot) + 1) : $file;
    }

    /** @return string|null absolute path of the class file, null for vendor classes and unknown names */
    public function fileFor(string $class): ?string
    {
        foreach ($this->psr4 as $prefix => $dir) {
            if (str_starts_with($class, $prefix)) {
                $path = $dir . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

                return is_file($path) ? $path : null;
            }
        }

        return null;
    }

    /** @return list<Node\Stmt> */
    public function parseFile(string $file): array
    {
        if (!isset($this->parsed[$file])) {
            $stmts = $this->parser->parse((string) file_get_contents($file)) ?? [];
            $traverser = new NodeTraverser(new NameResolver());
            $this->parsed[$file] = $traverser->traverse($stmts);
        }

        return $this->parsed[$file];
    }

    /** @return array{file: string, node: ClassLike}|null */
    public function find(string $class): ?array
    {
        $file = $this->fileFor($class);
        if ($file === null) {
            return null;
        }
        $node = (new NodeFinder())->findFirst(
            $this->parseFile($file),
            static fn (Node $n): bool => $n instanceof ClassLike && $n->namespacedName?->toString() === $class,
        );

        return $node instanceof ClassLike ? ['file' => $file, 'node' => $node] : null;
    }

    public function isInterface(string $class): bool
    {
        return ($this->find($class)['node'] ?? null) instanceof Interface_;
    }

    /** Looks the method up on the class, then on its parents that live in the app. @return array{file: string, node: ClassMethod}|null */
    public function findMethod(string $class, string $method): ?array
    {
        for ($guard = 0; $class !== '' && $guard < 20; $guard++) {
            $found = $this->find($class);
            if ($found === null) {
                return null;
            }
            $node = $found['node']->getMethod($method);
            if ($node !== null) {
                return ['file' => $found['file'], 'node' => $node];
            }
            $parent = $found['node'] instanceof Node\Stmt\Class_ ? $found['node']->extends : null;
            $class = $parent?->toString() ?? '';
        }

        return null;
    }

    /** Class name of a constructor-injected (promoted) or typed property. */
    public function propertyType(string $class, string $property): ?string
    {
        for ($guard = 0; $class !== '' && $guard < 20; $guard++) {
            $found = $this->find($class);
            if ($found === null) {
                return null;
            }
            $constructor = $found['node']->getMethod('__construct');
            foreach ($constructor?->params ?? [] as $param) {
                if ($param->isPromoted() && $param->var instanceof Node\Expr\Variable && $param->var->name === $property) {
                    return $param->type instanceof Node\Name ? $param->type->toString() : null;
                }
            }
            foreach ($found['node']->getProperties() as $declared) {
                foreach ($declared->props as $item) {
                    if ($item->name->toString() === $property) {
                        return $declared->type instanceof Node\Name ? $declared->type->toString() : null;
                    }
                }
            }
            $parent = $found['node'] instanceof Node\Stmt\Class_ ? $found['node']->extends : null;
            $class = $parent?->toString() ?? '';
        }

        return null;
    }
}
