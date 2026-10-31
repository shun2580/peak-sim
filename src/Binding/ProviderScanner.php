<?php

declare(strict_types=1);

namespace PeakSim\Binding;

use PeakSim\Model\Site;
use PeakSim\Source\ClassInfo;
use PeakSim\Source\SourceIndex;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Reads the registrations of one provider class: bind-style calls, `when()->needs()->give()`,
 * extend()/afterResolving(), and the `$bindings` / `$singletons` properties.
 */
final class ProviderScanner extends NodeVisitorAbstract
{
    private const BIND_KINDS = [
        'bind' => ['bind', false],
        'bindIf' => ['bind', true],
        'singleton' => ['singleton', false],
        'singletonIf' => ['singleton', true],
        'scoped' => ['scoped', false],
        'scopedIf' => ['scoped', true],
        'instance' => ['bind', false],
    ];

    private const DECORATORS = ['extend', 'afterResolving', 'callAfterResolving'];

    private const GIVE_UNREADABLE = ['giveTagged', 'giveConfig'];

    private string $context = '';

    private string $file = '';

    private int $branches = 0;

    private readonly ImplementationReader $reader;

    public function __construct(private readonly SourceIndex $source, private readonly BindingFacts $facts)
    {
        $this->reader = new ImplementationReader($source);
    }

    /** Scans the class, the traits it uses, and its app ancestors (ancestors first). */
    public function scanProvider(string $provider): void
    {
        $chain = [];
        for ($class = $provider; $class !== null && !isset($chain[$class]); $class = $info->parent ?? null) {
            $info = $this->source->findClass($class);
            if ($info === null) {
                break;
            }
            $chain[$class] = $info;
        }
        foreach (array_reverse($chain) as $info) {
            $this->scanClass($info, $info->fqcn);
            foreach ($info->traits as $trait) {
                $traitInfo = $this->source->findClass($trait);
                if ($traitInfo !== null) {
                    $this->scanClass($traitInfo, $info->fqcn);
                }
            }
        }
    }

    public function enterNode(Node $node): null
    {
        if (self::branches($node)) {
            $this->branches++;
        }
        if ($node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall) {
            $this->call($node);
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if (self::branches($node)) {
            $this->branches--;
        }

        return null;
    }

    private function scanClass(ClassInfo $info, string $context): void
    {
        $this->context = $context;
        $this->file = $info->file;
        $this->branches = 0;
        foreach ($info->node->getMethods() as $method) {
            if ($method->stmts !== null) {
                (new NodeTraverser($this))->traverse($method->stmts);
            }
        }
        foreach ($info->node->getProperties() as $property) {
            foreach ($property->props as $item) {
                $name = $item->name->toString();
                if (!$property->isStatic() && ($name === 'bindings' || $name === 'singletons') && $item->default instanceof Expr\Array_) {
                    $this->propertyBindings($item->default);
                }
            }
        }
    }

    private function propertyBindings(Expr\Array_ $array): void
    {
        foreach ($array->items as $item) {
            $at = $this->site($item);
            $abstract = $this->reader->className($item->key ?? $item->value, $this->context);
            if ($abstract === null) {
                $this->facts->unreadable($at, 'unresolvable: an entry of $bindings or $singletons has no constant key');
                continue;
            }
            $impl = $item->key === null ? $abstract : $this->reader->className($item->value, $this->context);
            $this->facts->add($impl === null
                ? BindingEntry::unreadable($abstract, null, $at, 'unresolvable: the value of a $bindings entry is not a class')
                : BindingEntry::of($abstract, null, [new Candidate($impl, $at, false, 'property')]));
        }
    }

    private function call(Expr\MethodCall|Expr\StaticCall $call): void
    {
        $name = $call->name instanceof Node\Identifier ? $call->name->toString() : null;
        if ($name === null) {
            return;
        }
        if (str_starts_with($name, 'give') && $call instanceof Expr\MethodCall) {
            $this->contextual($call, $name);

            return;
        }
        $onApp = $call instanceof Expr\MethodCall
            ? BindingSyntax::isApp($call->var) || ($name === 'callAfterResolving' && $call->var instanceof Expr\Variable && $call->var->name === 'this')
            : $call->class instanceof Node\Name && BindingSyntax::isAppFacade($call->class);
        if (!$onApp) {
            return;
        }
        if (isset(self::BIND_KINDS[$name])) {
            $this->bind($call, $name);
        } elseif (in_array($name, self::DECORATORS, true)) {
            $this->decorate($call, $name);
        }
    }

    private function bind(Expr\MethodCall|Expr\StaticCall $call, string $method): void
    {
        $at = $this->site($call);
        $first = $call->args[0] ?? null;
        $abstract = $first instanceof Node\Arg ? $this->reader->className($first->value, $this->context) : null;
        if ($abstract === null) {
            $this->facts->unreadable($at, "unresolvable: the abstract of {$method}() is not a constant");

            return;
        }
        [$kind, $always] = self::BIND_KINDS[$method];
        $second = $call->args[1] ?? null;
        $impl = $second instanceof Node\Arg && !self::isNull($second->value) ? $second->value : null;
        $this->facts->add($this->entry($abstract, null, $impl, $kind, $always, $at));
    }

    private function contextual(Expr\MethodCall $give, string $method): void
    {
        $needs = $give->var;
        $when = $needs instanceof Expr\MethodCall ? $needs->var : null;
        if (!$needs instanceof Expr\MethodCall || !self::called($needs, 'needs') || !$when instanceof Expr\MethodCall || !self::called($when, 'when') || !BindingSyntax::isApp($when->var)) {
            return;
        }
        $at = $this->site($give);
        $abstractArg = $needs->args[0] ?? null;
        $abstract = $abstractArg instanceof Node\Arg ? $this->reader->className($abstractArg->value, $this->context) : null;
        if ($abstract === null) {
            $this->facts->unreadable($at, 'unresolvable: the needs() argument of a contextual binding is not a constant');

            return;
        }
        $consumers = $this->consumers($when);
        $impl = ($give->args[0] ?? null) instanceof Node\Arg ? $give->args[0]->value : null;
        foreach ($consumers ?? [BindingEntry::ANY_CONSUMER] as $consumer) {
            $this->facts->add(in_array($method, self::GIVE_UNREADABLE, true) || $impl === null
                ? BindingEntry::unreadable($abstract, $consumer, $at, "unresolvable: {$method}() does not name a class")
                : $this->entry($abstract, $consumer, $impl, 'contextual', false, $at));
        }
    }

    /** @return list<string>|null null when the consumers of when() are not constant */
    private function consumers(Expr\MethodCall $when): ?array
    {
        $arg = $when->args[0] ?? null;
        if (!$arg instanceof Node\Arg) {
            return null;
        }
        $value = $this->source->evaluate($arg->value, $this->context);
        $list = $value->known ? (is_array($value->value) ? $value->value : [$value->value]) : null;
        if ($list === null || $list === [] || array_filter($list, static fn (mixed $c): bool => !is_string($c) || $c === '') !== []) {
            return null;
        }

        return array_values(array_map(static fn (string $c): string => ltrim($c, '\\'), $list));
    }

    private function decorate(Expr\MethodCall|Expr\StaticCall $call, string $method): void
    {
        $at = $this->site($call);
        $first = $call->args[0] ?? null;
        $abstract = $first instanceof Node\Arg ? $this->reader->className($first->value, $this->context) : null;
        if ($abstract === null) {
            $this->facts->unreadable($at, "unresolvable: the abstract of {$method}() is not a constant");

            return;
        }
        $this->facts->decorate($abstract, $at, "decorated: {$abstract} is wrapped by {$method}()");
    }

    private function entry(string $abstract, ?string $consumer, ?Expr $impl, string $kind, bool $conditional, Site $at): BindingEntry
    {
        if ($impl === null) {
            return BindingEntry::of($abstract, $consumer, [new Candidate($abstract, $at, $conditional || $this->branches > 0, $kind)]);
        }
        $read = $this->reader->read($impl, $this->context, $this->file);
        if ($read->failure !== null) {
            return BindingEntry::unreadable($abstract, $consumer, $at, 'unresolvable: ' . $read->failure);
        }
        $conditional = $conditional || $this->branches > 0 || count($read->classes) > 1;
        $candidates = [];
        foreach ($read->classes as $class => $site) {
            $candidates[] = new Candidate($class, self::isConstant($impl) ? $at : $site, $conditional, $kind);
        }

        return BindingEntry::of($abstract, $consumer, $candidates);
    }

    private function site(Node $at): Site
    {
        return new Site($this->source->relative($this->file), $at->getStartLine());
    }

    private static function called(Expr\MethodCall $call, string $name): bool
    {
        return $call->name instanceof Node\Identifier && $call->name->toString() === $name;
    }

    private static function isNull(Expr $e): bool
    {
        return $e instanceof Expr\ConstFetch && strtolower($e->name->toString()) === 'null';
    }

    /** A plain class constant or string: the site is the binding itself. */
    private static function isConstant(Expr $e): bool
    {
        return $e instanceof Expr\ClassConstFetch || $e instanceof Node\Scalar\String_;
    }

    private static function branches(Node $node): bool
    {
        return $node instanceof Stmt\If_ || $node instanceof Stmt\Switch_ || $node instanceof Expr\Ternary
            || $node instanceof Expr\Match_ || $node instanceof Stmt\Foreach_ || $node instanceof Stmt\For_
            || $node instanceof Stmt\While_ || $node instanceof Stmt\Do_ || $node instanceof Stmt\TryCatch
            || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction
            || $node instanceof Expr\BinaryOp\Coalesce;
    }
}
