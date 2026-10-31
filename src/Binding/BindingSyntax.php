<?php

declare(strict_types=1);

namespace PeakSim\Binding;

use PhpParser\Node;
use PhpParser\Node\Expr;

/** Which expressions are the application container. */
final class BindingSyntax
{
    private const FACADES = ['Illuminate\Support\Facades\App', 'App'];

    /** `$this->app`, `$app`, `app()`. */
    public static function isApp(Expr $e): bool
    {
        return match (true) {
            $e instanceof Expr\PropertyFetch => $e->var instanceof Expr\Variable && $e->var->name === 'this'
                && $e->name instanceof Node\Identifier && $e->name->toString() === 'app',
            $e instanceof Expr\Variable => $e->name === 'app',
            $e instanceof Expr\FuncCall => $e->name instanceof Node\Name && strtolower($e->name->toString()) === 'app' && $e->args === [],
            default => false,
        };
    }

    /** The `App` facade, as imported or as the global alias. */
    public static function isAppFacade(Node\Name $name): bool
    {
        return in_array($name->toString(), self::FACADES, true);
    }
}
