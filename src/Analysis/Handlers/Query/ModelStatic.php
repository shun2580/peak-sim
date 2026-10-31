<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Handlers\Query;

use PeakSim\Analysis\Contracts\CallHandler;
use PeakSim\Analysis\Contracts\CallSite;
use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Type\BuilderT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\ObjectT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Source\ParseFailed;
use PhpParser\Node;

/**
 * `Model::x()`, `new Model` and the DB facade: where a query starts.
 *
 * A static call on a model is a call on a fresh builder for it, so it is handed to BuilderOps. A method the model
 * defines itself (a real static method) is not ours: the engine walks its body.
 */
final class ModelStatic implements CallHandler
{
    /** Static switches that change how models behave and read nothing. */
    private const CONFIGURATION = [
        'unguard', 'reguard', 'preventlazyloading', 'shouldbestrict', 'preventsilentlydiscardingattributes',
        'preventaccessingmissingattributes', 'observe', 'flusheventlisteners', 'clearbootedmodels',
    ];

    private readonly DbOps $db;

    public function __construct(private readonly BuilderOps $ops = new BuilderOps())
    {
        $this->db = new DbOps();
    }

    public function handles(CallSite $c, EvalContext $ctx): bool
    {
        if ($c->receiver instanceof ObjectT) {
            return $c->receiver->class === DbOps::FACADE && DbOps::handlesName($c->name);
        }
        if ($c->receiver !== null || $c->staticClass === null) {
            return false;
        }
        if ($c->staticClass === DbOps::FACADE) {
            return DbOps::handlesName($c->name);
        }
        try {
            if (!$ctx->models()->isModel($c->staticClass)) {
                return false;
            }
            if ($c->node instanceof Node\Expr\New_) {
                return true;
            }

            return !$ctx->source()->resolveMethod($c->staticClass, $c->name)->isFound();
        } catch (ParseFailed) {
            return false;
        }
    }

    public function handle(CallSite $c, EvalContext $ctx): Type
    {
        if ($c->receiver instanceof ObjectT || $c->staticClass === DbOps::FACADE) {
            return $this->db->apply($c, $ctx);
        }
        $class = (string) $c->staticClass;
        if ($c->node instanceof Node\Expr\New_) {
            foreach (array_keys($c->args) as $i) {
                $c->argType($i);
            }

            return new ModelT($class);
        }
        if (in_array(strtolower($c->name), self::CONFIGURATION, true)) {
            foreach (array_keys($c->args) as $i) {
                $c->argType($i);
            }

            return new PlainT();
        }
        $builder = $this->ops->newBuilder($class, $c->node, new QueryKit($ctx));
        if (!$builder instanceof BuilderT) {
            return $builder;
        }
        if (strtolower($c->name) === 'query') {
            return $builder;
        }

        return $this->ops->apply($builder, $c, $ctx);
    }
}
