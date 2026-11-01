<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Blade;

use PeakSim\Model\Gap;
use PeakSim\Source\SourceIndex;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Finds a view by name and turns it into something the engine can run: the transpiled PHP wrapped in a closure.
 * A template is read, transpiled and parsed once; every render shares the nodes.
 *
 * The closure body is the template's statements, preceded by one marker call per transpiler gap, each on the
 * template line of its directive. Walking the closure with the template's path as its file makes every event,
 * gap and evidence line come out as `resources/views/...:<template line>`.
 */
final class ViewHandlerTemplates
{
    public const TEMPLATE = 'peaksim:template';
    public const GAP = 'peaksim:gap';
    public const LOOP_VARS = 'peaksim:loop-vars';
    public const GAP_MARKER = '__peak_gap';

    private const DEFAULT_PATH = 'resources/views';
    private const PATH_HELPERS = [
        'resource_path' => 'resources',
        'base_path' => '',
        'app_path' => 'app',
        'storage_path' => 'storage',
        'database_path' => 'database',
    ];

    private readonly Parser $parser;

    /** @var list<string>|null app-relative directories, in lookup order */
    private ?array $paths = null;

    /** @var array<string, Expr\Closure|string> relative path => compiled closure, or why it could not be */
    private array $compiled = [];

    public function __construct(
        private readonly string $appRoot,
        private readonly ?string $viewPath,
        private readonly ViewHandlerParents $parents,
    ) {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * The app-relative path of the view called $name, or null when there is none (or the name is not a view name).
     *
     * A "/" is a directory step like a "." (Laravel turns both into a path). A name with a ".." step, a leading "/",
     * a "::" or any other character is not a view name; and a file is only taken when it really lies under the
     * directory it was found in, links resolved.
     */
    public function locate(string $name, SourceIndex $source): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]+(?:[.\/][A-Za-z0-9_-]+)*$/D', $name) !== 1) {
            return null;
        }
        $file = str_replace('.', '/', $name) . '.blade.php';
        foreach ($this->paths($source) as $dir) {
            $relative = ($dir === '' ? '' : $dir . '/') . $file;
            if (is_file($this->appRoot . '/' . $relative) && $this->liesUnder($dir, $relative)) {
                return $relative;
            }
        }

        return null;
    }

    /** Whether the file, once links are followed, is inside the directory (both app-relative). */
    private function liesUnder(string $dir, string $relative): bool
    {
        $base = realpath($this->appRoot . ($dir === '' ? '' : '/' . $dir));
        $real = realpath($this->appRoot . '/' . $relative);

        return $base !== false && $real !== false && str_starts_with($real, rtrim($base, '/') . '/');
    }

    /** @return Expr\Closure|string the closure that runs the view, or the reason it cannot be run */
    public function compile(string $relative): Expr\Closure|string
    {
        return $this->compiled[$relative] ??= $this->build($relative);
    }

    // ----- where views live ---------------------------------------------------------------------------

    /** @return list<string> */
    private function paths(SourceIndex $source): array
    {
        if ($this->paths !== null) {
            return $this->paths;
        }
        if ($this->viewPath !== null) {
            return $this->paths = [$this->inside($this->viewPath) ?? self::DEFAULT_PATH];
        }

        return $this->paths = $this->fromConfig($source) ?: [self::DEFAULT_PATH];
    }

    /** @return list<string> */
    private function fromConfig(SourceIndex $source): array
    {
        try {
            $stmts = $source->parse('config/view.php');
        } catch (\Throwable) {
            return [];
        }
        foreach ($stmts as $stmt) {
            if (!$stmt instanceof Stmt\Return_ || !$stmt->expr instanceof Expr\Array_) {
                continue;
            }
            foreach ($stmt->expr->items as $item) {
                if ($item->key instanceof Scalar\String_ && $item->key->value === 'paths' && $item->value instanceof Expr\Array_) {
                    return $this->pathList($item->value);
                }
            }
        }

        return [];
    }

    /** @return list<string> */
    private function pathList(Expr\Array_ $list): array
    {
        $paths = [];
        foreach ($list->items as $item) {
            $path = $this->pathOf($item->value);
            if ($path !== null && !in_array($path, $paths, true)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /** A path written as a literal or as one of the path helpers; anything else cannot be read without running it. */
    private function pathOf(Expr $e): ?string
    {
        if ($e instanceof Scalar\String_) {
            return $this->inside($e->value);
        }
        if ($e instanceof Expr\FuncCall && $e->name instanceof Name && isset(self::PATH_HELPERS[$e->name->toString()])) {
            $base = self::PATH_HELPERS[$e->name->toString()];
            $arg = $e->args === [] ? '' : ($e->args[0]->value instanceof Scalar\String_ ? trim($e->args[0]->value->value, '/') : null);

            return $arg === null ? null : $this->inside(trim($base . '/' . $arg, '/'));
        }

        return null;
    }

    /** $path as a directory under the app root, relative to it; null when it points elsewhere. */
    private function inside(string $path): ?string
    {
        $root = rtrim($this->appRoot, '/');
        if (str_starts_with($path, '/')) {
            if ($path !== $root && !str_starts_with($path, $root . '/')) {
                return null;
            }
            $path = substr($path, strlen($root));
        }
        $path = trim($path, '/');

        return str_contains('/' . $path . '/', '/../') ? null : $path;
    }

    // ----- compiling ------------------------------------------------------------------------------------

    private function build(string $relative): Expr\Closure|string
    {
        $source = @file_get_contents($this->appRoot . '/' . $relative);
        if ($source === false) {
            return "cannot read {$relative}";
        }
        $view = BladeToPhp::transpile($relative, $source);
        try {
            $stmts = $this->parser->parse($view->php) ?? [];
            $stmts = (new NodeTraverser(new NameResolver()))->traverse($stmts);
        } catch (\PhpParser\Error $e) {
            return "{$relative} could not be read as PHP after transpiling: {$e->getRawMessage()} on line {$e->getStartLine()}";
        }
        (new NodeTraverser(self::loopVariables()))->traverse($stmts);
        $this->parents->connect($stmts);

        $closure = new Expr\Closure(
            ['stmts' => [...$this->gapMarkers($view->gaps), ...$stmts]],
            ['startLine' => 1, self::TEMPLATE => $relative],
        );
        $this->parents->connect([$closure]);

        return $closure;
    }

    /**
     * One marker call per gap, on the line of the directive. The handler turns each into the gap itself.
     *
     * @param list<Gap> $gaps
     * @return list<Stmt\Expression>
     */
    private function gapMarkers(array $gaps): array
    {
        $markers = [];
        foreach ($gaps as $gap) {
            $attrs = ['startLine' => $gap->site->line, 'endLine' => $gap->site->line, self::GAP => $gap];
            $markers[] = new Stmt\Expression(new Expr\FuncCall(new Name(self::GAP_MARKER, $attrs), [], $attrs), $attrs);
        }

        return $markers;
    }

    /** Marks each include / extends call with the names of the foreach variables around it: Blade passes them on. */
    private static function loopVariables(): NodeVisitorAbstract
    {
        return new class extends NodeVisitorAbstract {
            /** @var list<list<string>> */
            private array $loops = [];

            public function enterNode(Node $node): null
            {
                if ($node instanceof Stmt\Foreach_) {
                    $names = [];
                    foreach ([$node->keyVar, $node->valueVar] as $var) {
                        if ($var instanceof Expr\Variable && is_string($var->name)) {
                            $names[] = $var->name;
                        }
                    }
                    $this->loops[] = $names;
                }
                if ($node instanceof Expr\FuncCall && $node->name instanceof Name
                    && in_array($node->name->toString(), ['__peak_include', '__peak_extends'], true)) {
                    $node->setAttribute(ViewHandlerTemplates::LOOP_VARS, array_values(array_unique(array_merge([], ...$this->loops))));
                }

                return null;
            }

            public function leaveNode(Node $node): null
            {
                if ($node instanceof Stmt\Foreach_) {
                    array_pop($this->loops);
                }

                return null;
            }
        };
    }
}
