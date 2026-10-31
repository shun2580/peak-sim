<?php

declare(strict_types=1);

namespace PeakSim\Binding;

use PeakSim\Model\Site;
use PeakSim\Source\ParseFailed;
use PeakSim\Source\SourceIndex;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Finds the app's own service providers, in listing order: bootstrap/providers.php, then the
 * `providers` array of config/app.php, then withProviders() in bootstrap/app.php. Vendor providers
 * and package auto-discovery are not read.
 */
final class ProviderLocator
{
    private const PROVIDERS = 'bootstrap/providers.php';
    private const CONFIG = 'config/app.php';
    private const BOOTSTRAP = 'bootstrap/app.php';

    /** @var list<string> */
    private array $found = [];

    /** @var list<array{0: Site, 1: string}> */
    private array $problems = [];

    public function __construct(private readonly SourceIndex $source)
    {
    }

    /** @return array{0: list<string>, 1: list<array{0: Site, 1: string}>} provider classes, and what could not be read */
    public function locate(): array
    {
        $this->found = [];
        $this->problems = [];
        $this->fromProvidersFile();
        $this->fromConfig();
        $this->fromBootstrap();

        return [$this->found, $this->problems];
    }

    private function fromProvidersFile(): void
    {
        $stmts = $this->parsed(self::PROVIDERS);
        foreach ($stmts ?? [] as $stmt) {
            if (!$stmt instanceof Stmt\Return_ || $stmt->expr === null) {
                continue;
            }
            if (!$stmt->expr instanceof Expr\Array_) {
                $this->problems[] = [new Site(self::PROVIDERS, $stmt->getStartLine()), 'the provider list is not an array literal'];
                continue;
            }
            foreach ($stmt->expr->items as $item) {
                if (!$this->take($item->value)) {
                    $this->problems[] = [new Site(self::PROVIDERS, $item->getStartLine()), 'a provider entry is not a class constant'];
                }
            }
        }
    }

    private function fromConfig(): void
    {
        $stmts = $this->parsed(self::CONFIG);
        foreach ((new NodeFinder())->findInstanceOf($stmts ?? [], Stmt\Return_::class) as $return) {
            if (!$return->expr instanceof Expr\Array_) {
                continue;
            }
            foreach ($return->expr->items as $item) {
                if ($item->key instanceof String_ && $item->key->value === 'providers') {
                    $this->collect($item->value);
                }
            }
        }
    }

    private function fromBootstrap(): void
    {
        $stmts = $this->parsed(self::BOOTSTRAP);
        foreach ((new NodeFinder())->findInstanceOf($stmts ?? [], Expr\MethodCall::class) as $call) {
            if ($call->name instanceof Node\Identifier && $call->name->toString() === 'withProviders' && ($call->args[0] ?? null) instanceof Node\Arg) {
                $this->collect($call->args[0]->value);
            }
        }
    }

    /** Every `X::class` and every string naming an app class inside the expression. */
    private function collect(Expr $e): void
    {
        $finder = new NodeFinder();
        foreach ($finder->find($e, static fn (Node $n): bool => $n instanceof Expr\ClassConstFetch || $n instanceof String_) as $n) {
            $this->take($n);
        }
    }

    /** Adds the class when it is an app provider; true when the node names a class (app or vendor). */
    private function take(Node $n): bool
    {
        $name = match (true) {
            $n instanceof Expr\ClassConstFetch => $n->class instanceof Node\Name && $n->name instanceof Node\Identifier && $n->name->toString() === 'class'
                ? $n->class->toString()
                : null,
            $n instanceof String_ => ltrim($n->value, '\\'),
            default => null,
        };
        if ($name === null) {
            return false;
        }
        try {
            $app = $this->source->findClass($name) !== null;
        } catch (ParseFailed $e) {
            $this->problems[] = [new Site(self::fileOf($e, self::PROVIDERS), $n->getStartLine()), 'unreadable provider ' . $name . ': ' . $e->getMessage()];

            return true;
        }
        if ($app && !in_array($name, $this->found, true)) {
            $this->found[] = $name;
        }

        return true;
    }

    /** @return list<Stmt>|null null when the file is absent or has a syntax error (the latter is a problem) */
    private function parsed(string $file): ?array
    {
        if (!in_array($file, $this->source->files(dirname($file)), true)) {
            return null;
        }
        try {
            return $this->source->parse($file);
        } catch (ParseFailed $e) {
            $this->problems[] = [new Site($file, 1), $e->getMessage()];

            return null;
        }
    }

    /** The file a ParseFailed names ("syntax error in <file>: …"), or the fallback. */
    public static function fileOf(ParseFailed $e, string $fallback): string
    {
        return preg_match('/ in (\S+?): /', $e->getMessage(), $m) === 1 ? $m[1] : $fallback;
    }
}
