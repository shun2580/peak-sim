<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Blade;

use PeakSim\Analysis\Contracts\CallHandler;
use PeakSim\Analysis\Contracts\CallSite;
use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Type\ClosureT;
use PeakSim\Analysis\Type\ObjectT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Model\Gap;
use PeakSim\Model\GapCode;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;

/**
 * `view()`, `View::make()`, `response()->view()` and `->with()`: the point where a Blade template's cost enters
 * the action. The template is transpiled to PHP (BladeToPhp), read by php-parser, and walked by the engine's own
 * walker as a closure over the view's variables, under the loops around the call. `@include` and `@extends` come
 * back here as `__peak_include` / `__peak_extends` calls and are resolved the same way.
 *
 * Register it before the known-calls handler (a smaller priority number): `view()` is a vendor helper and would
 * otherwise be taken as a safe, free call. Its other calls cannot clash with anything else; handles() is exact.
 */
final class ViewHandler implements CallHandler
{
    public const VIEW_CLASS = 'Illuminate\View\View';
    public const FACTORY_CLASS = 'Illuminate\View\Factory';
    public const RESPONSE_CLASS = 'Illuminate\Http\Response';

    public const ERRORS_CLASS = 'Illuminate\Support\ViewErrorBag';

    /** Variables every template has; a key of the same name in the view's data replaces them. */
    private const BUILT_IN = ['loop', '__env', '__data', 'obLevel', 'slot', 'attributes'];

    /** Templates rendering inside templates, at most. Deeper than that is a gap, as for methods. */
    private const MAX_NESTING = 20;

    private readonly ViewHandlerParents $parents;
    private readonly ViewHandlerTemplates $templates;
    private readonly ViewHandlerData $data;

    /** @var list<string> templates being rendered, outermost first */
    private array $active = [];

    /** @var list<array<string, Type>> the variables of each template being rendered, outermost first */
    private array $given = [];

    /**
     * @param string $appRoot the app to read views from
     * @param string|null $viewPath a directory (app-relative or under the root) that replaces config/view.php's paths
     */
    public function __construct(string $appRoot, ?string $viewPath = null)
    {
        $this->parents = new ViewHandlerParents();
        $this->templates = new ViewHandlerTemplates($appRoot, $viewPath, $this->parents);
        $this->data = new ViewHandlerData();
    }

    public function handles(CallSite $c, EvalContext $ctx): bool
    {
        $name = strtolower($c->name);
        $node = $c->node;
        if ($node instanceof Expr\PropertyFetch) {
            return $this->isLoopRead($node, $c->receiver);
        }
        if ($node instanceof Expr\FuncCall) {
            return in_array($name, ['view', '__peak_include', '__peak_extends', ViewHandlerTemplates::GAP_MARKER], true);
        }
        if ($node instanceof Expr\StaticCall) {
            return ($name === 'make' && $this->isFacade($c->staticClass, 'View', $ctx))
                || ($name === 'view' && $this->isFacade($c->staticClass, 'Response', $ctx));
        }
        if ($node instanceof Expr\MethodCall) {
            return match ($name) {
                'with' => $this->isView($c->receiver),
                'view' => $this->isResponseFactory($c),
                'make' => $c->receiver instanceof ObjectT && ltrim($c->receiver->class, '\\') === self::FACTORY_CLASS,
                default => false,
            };
        }

        return false;
    }

    public function handle(CallSite $c, EvalContext $ctx): Type
    {
        $name = strtolower($c->name);
        $node = $c->node;

        if ($node instanceof Expr\PropertyFetch) {
            // `$loop->index`, `$loop->first`...: the loop counters, plain values that touch nothing.
            return new PlainT();
        }
        if ($name === ViewHandlerTemplates::GAP_MARKER) {
            return $this->gapMarker($c, $ctx);
        }
        if ($name === '__peak_include' || $name === '__peak_extends') {
            return $this->include($c, $ctx);
        }
        if ($node instanceof Expr\FuncCall) {
            return $c->args === [] ? new ObjectT(self::FACTORY_CLASS) : $this->make($c, $ctx, 0, true, self::VIEW_CLASS);
        }
        if ($name === 'with') {
            return $this->with($c, $ctx);
        }
        if ($name === 'make') {
            return $this->make($c, $ctx, 0, true, self::VIEW_CLASS);
        }

        // Response::view() and response()->view(): name, data, status, headers. The response is built at once.
        return $this->make($c, $ctx, 0, false, self::RESPONSE_CLASS);
    }

    /**
     * `$loop->...` while `$loop` is still the built-in (a plain value). Reading a property on any other plain value is a
     * gap (CallResolver); the loop counters are the one read a template makes on a built-in that nothing can hide behind.
     */
    private function isLoopRead(Expr\PropertyFetch $node, ?Type $receiver): bool
    {
        return $receiver instanceof PlainT && $node->var instanceof Expr\Variable && $node->var->name === 'loop';
    }

    // ----- view(), View::make(), response()->view() -------------------------------------------------------

    /**
     * @param bool $viewCall true for view() / View::make(): their third argument is more data, and a ->with()
     *                       right after the call means the template is not rendered yet
     */
    private function make(CallSite $c, EvalContext $ctx, int $nameAt, bool $viewCall, string $returns): Type
    {
        $dataAt = $nameAt + 1;
        $vars = $this->data->read($c->argNode($dataAt), $ctx);
        if ($viewCall) {
            $vars = [...$vars, ...$this->data->read($c->argNode($dataAt + 1), $ctx)];
        } elseif ($c->argNode($dataAt + 1) !== null) {
            for ($i = $dataAt + 1; $c->argNode($i) !== null; $i++) {
                $c->argType($i);
            }
        }

        $view = $this->viewNamed($c->argNode($nameAt), $vars, $c->node, $ctx);
        if (!$view instanceof ClosureT) {
            return $view;
        }
        if ($viewCall && $this->isReceiverOfWith($c->node, $ctx)) {
            return $view;
        }

        return $this->render($view, $c->node, $ctx, $returns);
    }

    private function with(CallSite $c, EvalContext $ctx): Type
    {
        $added = $this->data->readWith($c, $ctx);
        $receiver = $c->receiver;
        if (!$receiver instanceof ClosureT) {
            return $ctx->gap(GapCode::UNRESOLVED_CALL, $c->node, 'with() on a view that was already built: the data it adds is not seen by the template');
        }
        $view = $receiver->withCaptured([...$receiver->captured, ...$added]);

        return $this->isReceiverOfWith($c->node, $ctx) ? $view : $this->render($view, $c->node, $ctx, self::VIEW_CLASS);
    }

    // ----- @include, @extends and the gap markers -----------------------------------------------------------

    private function include(CallSite $c, EvalContext $ctx): Type
    {
        $explicit = $this->data->read($c->argNode(1), $ctx);
        $inherited = $this->given === [] ? [] : $this->given[array_key_last($this->given)];
        $loop = [];
        $loopVars = $c->node->getAttribute(ViewHandlerTemplates::LOOP_VARS);
        foreach (is_array($loopVars) ? $loopVars : [] as $var) {
            if (is_string($var)) {
                $loop[$var] = $ctx->evaluate(new Expr\Variable($var, $c->node->getAttributes()));
            }
        }

        $kind = strtolower($c->name) === '__peak_extends' ? '@extends' : '@include';
        $view = $this->viewNamed($c->argNode(0), [...$inherited, ...$loop, ...$explicit], $c->node, $ctx, $kind);

        return $view instanceof ClosureT ? $this->render($view, $c->node, $ctx, self::VIEW_CLASS) : $view;
    }

    private function gapMarker(CallSite $c, EvalContext $ctx): Type
    {
        $gap = $c->node->getAttribute(ViewHandlerTemplates::GAP);
        if (!$gap instanceof Gap) {
            return new PlainT();
        }

        return $ctx->gap($gap->code, $c->node, $gap->message, $gap->affects);
    }

    // ----- finding and running a template -------------------------------------------------------------------

    /**
     * The view called by $nameNode with $vars as its variables; a gap (as the UnknownT it returns) when it cannot be.
     *
     * @param array<string, Type> $vars
     */
    private function viewNamed(?Expr $nameNode, array $vars, Node $at, EvalContext $ctx, string $what = 'view'): Type
    {
        $const = $nameNode === null ? null : $ctx->source()->evaluate($nameNode, null);
        if ($const === null || !$const->known || !is_string($const->value)) {
            return $ctx->gap(GapCode::VIEW_NOT_FOUND, $at, "the name of the view in {$what} is not a constant string: which template it is cannot be told");
        }
        $relative = $this->templates->locate($const->value, $ctx->source());
        if ($relative === null) {
            return $ctx->gap(GapCode::VIEW_NOT_FOUND, $at, "view [{$const->value}] has no template file");
        }
        $closure = $this->templates->compile($relative);
        if (is_string($closure)) {
            return $ctx->gap(GapCode::PARSE_FAILED, $at, $closure);
        }

        $captured = ['errors' => new ObjectT(self::ERRORS_CLASS)];
        foreach (self::BUILT_IN as $name) {
            $captured[$name] = new PlainT();
        }
        foreach ($vars as $name => $type) {
            $captured[$name] = $type;
        }

        return new ClosureT($closure, $captured, $relative);
    }

    /** Walk the template under the loops around the call. A template already being walked is a recursion gap. */
    private function render(ClosureT $view, Node $at, EvalContext $ctx, string $returns): Type
    {
        if (in_array($view->file, $this->active, true)) {
            return $ctx->gap(GapCode::RECURSION, $at, "view {$view->file} includes itself, directly or through other views: not walked again");
        }
        if (count($this->active) >= self::MAX_NESTING) {
            return $ctx->gap(GapCode::DEPTH_LIMIT, $at, 'views include each other more than ' . self::MAX_NESTING . ' deep: not followed');
        }

        $this->active[] = $view->file;
        $this->given[] = $view->captured;
        try {
            $ctx->invokeClosure($view, []);
        } finally {
            array_pop($this->active);
            array_pop($this->given);
        }

        return new ObjectT($returns);
    }

    // ----- recognising the calls ------------------------------------------------------------------------------

    private function isView(?Type $receiver): bool
    {
        return ($receiver instanceof ClosureT && $receiver->node->hasAttribute(ViewHandlerTemplates::TEMPLATE))
            || ($receiver instanceof ObjectT && ltrim($receiver->class, '\\') === self::VIEW_CLASS);
    }

    private function isResponseFactory(CallSite $c): bool
    {
        if ($c->receiver instanceof ObjectT && str_ends_with(ltrim($c->receiver->class, '\\'), 'ResponseFactory')) {
            return true;
        }
        $var = $c->node instanceof Expr\MethodCall ? $c->node->var : null;

        return $var instanceof Expr\FuncCall && $var->name instanceof Name
            && strtolower($var->name->toString()) === 'response' && $var->args === [];
    }

    /** `View` / `Response`: the facade, its global alias, or the alias resolved into the current namespace. */
    private function isFacade(?string $class, string $short, EvalContext $ctx): bool
    {
        if ($class === null) {
            return false;
        }
        $class = ltrim($class, '\\');

        return $class === 'Illuminate\Support\Facades\\' . $short
            || $class === $short
            || ($short === 'View' && $class === self::FACTORY_CLASS)
            || (str_ends_with($class, '\\' . $short) && $ctx->source()->findClass($class) === null);
    }

    /** True when this call is directly followed by ->with(): the data is still to come, so the view is not rendered yet. */
    private function isReceiverOfWith(Node $call, EvalContext $ctx): bool
    {
        $parent = $this->parents->parentOf($call, $ctx);

        return $parent instanceof Expr\MethodCall && $parent->var === $call
            && $parent->name instanceof Identifier && strtolower($parent->name->toString()) === 'with';
    }
}
