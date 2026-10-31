<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Blade;

use PeakSim\Analysis\Contracts\EvalContext;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Who a call is the receiver of. `view('t')->with(...)` must not render at view(): the data comes later. The
 * engine hands a handler the call, not its parent, so the parent is looked up here from the parsed file (the
 * index parses each file once, so these are the nodes the engine walks).
 */
final class ViewHandlerParents
{
    private const KEY = 'peaksim:parent';

    /** @var array<string, true> */
    private array $connected = [];

    /** The parent of $n; null when it has none or the file could not be read. */
    public function parentOf(Node $n, EvalContext $ctx): ?Node
    {
        if (!$n->hasAttribute(self::KEY)) {
            $this->connectFile($ctx->site($n)->file, $ctx);
        }
        $parent = $n->getAttribute(self::KEY);

        return $parent instanceof Node ? $parent : null;
    }

    /** @param array<Node> $stmts */
    public function connect(array $stmts): void
    {
        (new NodeTraverser(new class extends NodeVisitorAbstract {
            /** @var list<Node> */
            private array $stack = [];

            public function enterNode(Node $node): null
            {
                $node->setAttribute(ViewHandlerParents::attribute(), $this->stack === [] ? null : $this->stack[array_key_last($this->stack)]);
                $this->stack[] = $node;

                return null;
            }

            public function leaveNode(Node $node): null
            {
                array_pop($this->stack);

                return null;
            }
        }))->traverse($stmts);
    }

    public static function attribute(): string
    {
        return self::KEY;
    }

    private function connectFile(string $file, EvalContext $ctx): void
    {
        if (isset($this->connected[$file]) || str_ends_with($file, '.blade.php')) {
            return;
        }
        $this->connected[$file] = true;
        try {
            $this->connect($ctx->source()->parse($file));
        } catch (\Throwable) {
            // A file that cannot be read has no parents; the caller then treats the call as having none.
        }
    }
}
