<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Serialization;

use PeakSim\Analysis\Contracts\CallHandler;
use PeakSim\Analysis\Contracts\CallSite;
use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Type\ClosureT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Model\GapCode;
use PhpParser\Node\Expr;

/**
 * `Attribute::get(fn)`, `Attribute::make(get: fn, set: fn)` and `new Attribute(get: fn)`: the new-style accessor of
 * a model. The result is the getter closure (what runs when the attribute is read), so the caller of the accessor
 * method can run it with the model as $this. The setter is not run: it only runs on assignment.
 *
 * Only the building of the Attribute is modelled here; it touches no database and calls nothing back.
 */
final class AttributeCasts implements CallHandler
{
    public const ATTRIBUTE = 'Illuminate\Database\Eloquent\Casts\Attribute';

    public function handles(CallSite $c, EvalContext $ctx): bool
    {
        if ($c->receiver !== null || $c->staticClass === null || ltrim($c->staticClass, '\\') !== self::ATTRIBUTE) {
            return false;
        }
        if ($c->node instanceof Expr\New_) {
            return true;
        }

        return $c->node instanceof Expr\StaticCall && in_array(strtolower($c->name), ['get', 'make'], true);
    }

    public function handle(CallSite $c, EvalContext $ctx): Type
    {
        $types = [];
        foreach ($c->args as $i => $arg) {
            $types[$i] = $c->argType($i);
        }
        $getter = $this->getterIndex($c);
        if ($getter === null) {
            return new PlainT();
        }
        $type = $types[$getter];
        if ($type instanceof ClosureT) {
            return $type;
        }

        return $ctx->gap(GapCode::ACCESSOR_NOT_ANALYZED, $c->node, 'the getter of this Attribute is not a closure the analysis can run: what reading the attribute does is not known');
    }

    /** The index of the argument that is the getter, or null when there is none (a set-only attribute). */
    private function getterIndex(CallSite $c): ?int
    {
        foreach ($c->args as $i => $arg) {
            if ($arg->name !== null && $arg->name->toString() === 'get') {
                return $i;
            }
        }
        if (strtolower($c->name) === 'get') {
            return isset($c->args[0]) ? 0 : null;
        }
        // make($get, $set) and new Attribute($get, $set): the first positional argument.
        foreach ($c->args as $i => $arg) {
            if ($arg->name === null) {
                return $i === 0 ? 0 : null;
            }
        }

        return null;
    }
}
