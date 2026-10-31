<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Serialization;

use PeakSim\Analysis\Contracts\CallHandler;
use PeakSim\Analysis\Contracts\CallSite;
use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Engine\ActionReturnHook;
use PeakSim\Analysis\Handlers\Collection\CollectionOps;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\ObjectT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\ResourceT;
use PeakSim\Analysis\Type\ShapeT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Model\GapCode;
use PhpParser\Node;
use PhpParser\Node\Expr;

/**
 * Where a value becomes JSON, and so where the accessors and the resources run:
 *
 *  - the value an action returns (the engine calls onActionReturn() once for the route's action),
 *  - `response()->json(x)` and `Response::json(x)`,
 *  - `->toArray()`, `->toJson()`, `->jsonSerialize()` on a model, a list of models or an array holding them,
 *  - `json_encode(x)`,
 *  - `->resolve()`, `->toArray()`, `->response()` and the like on a resource.
 *
 * Every call is its own serialisation; nothing is remembered between two of them. The sink returns PlainT (the
 * JSON), except `$list->toArray()` / `->toJson()`, which go on to the Collection handler for their usual meaning.
 */
final class ResponseSink implements CallHandler, ActionReturnHook
{
    /** Methods of a resource that produce its array or response, which runs toArray() for the elements. */
    public const RESOURCE_METHODS = ['resolve', 'toarray', 'tojson', 'jsonserialize', 'response', 'toresponse'];

    private const SERIALIZING = ['toarray', 'tojson', 'jsonserialize'];

    private const RESPONSE_FACTORIES = ['Illuminate\Contracts\Routing\ResponseFactory', 'Illuminate\Routing\ResponseFactory'];

    private const RESPONSE_FACADE = 'Illuminate\Support\Facades\Response';

    private const MODEL = 'Illuminate\Database\Eloquent\Model';

    public function __construct(private readonly Serializer $serializer)
    {
    }

    public function onActionReturn(Type $returned, Node $action, EvalContext $ctx): void
    {
        $this->serializer->serialize($returned, $action, $ctx);
    }

    public function handles(CallSite $c, EvalContext $ctx): bool
    {
        $name = strtolower($c->name);
        $node = $c->node;
        if ($node instanceof Expr\FuncCall) {
            return $name === 'json_encode';
        }
        if ($node instanceof Expr\StaticCall) {
            $class = ltrim((string) $c->staticClass, '\\');

            return ($class === self::RESPONSE_FACADE && $name === 'json') || ($class === self::MODEL && in_array($name, self::SERIALIZING, true));
        }
        if (!$node instanceof Expr\MethodCall && !$node instanceof Expr\NullsafeMethodCall) {
            return false;
        }
        $receiver = $c->receiver;

        return match (true) {
            $receiver instanceof ObjectT => $name === 'json' && in_array(ltrim($receiver->class, '\\'), self::RESPONSE_FACTORIES, true),
            $receiver instanceof ResourceT => in_array($name, self::RESOURCE_METHODS, true),
            $receiver instanceof ListT, $receiver instanceof ShapeT => in_array($name, self::SERIALIZING, true),
            // A toArray() the app's model declares is the app's code: the engine walks it.
            $receiver instanceof ModelT => in_array($name, self::SERIALIZING, true) && $ctx->source()->resolveMethod($receiver->class, $c->name)->method === null,
            default => false,
        };
    }

    public function handle(CallSite $c, EvalContext $ctx): Type
    {
        $name = strtolower($c->name);
        $node = $c->node;
        if ($node instanceof Expr\FuncCall || ($node instanceof Expr\StaticCall && $name === 'json') || ($c->receiver instanceof ObjectT)) {
            return $this->ofArgument($c, $ctx);
        }
        if ($node instanceof Expr\StaticCall) {
            return $this->parentOfModel($c, $ctx);
        }
        $receiver = $c->receiver;
        \assert($receiver !== null);
        foreach (array_keys($c->args) as $i) {
            $c->argType($i);
        }
        $this->serializer->serialize($receiver, $node, $ctx);
        if ($receiver instanceof ListT && in_array($name, ['toarray', 'tojson'], true)) {
            $ops = new CollectionOps();

            return $ops->handles($c, $ctx) ? $ops->handle($c, $ctx) : new PlainT();
        }

        return new PlainT();
    }

    /** json_encode(x), response()->json(x), Response::json(x): the first argument is what is serialised. */
    private function ofArgument(CallSite $c, EvalContext $ctx): Type
    {
        foreach (array_keys($c->args) as $i) {
            $type = $c->argType($i);
            if ($i === 0) {
                $this->serializer->serialize($type, $c->node, $ctx);
            }
        }

        return new PlainT();
    }

    /** `parent::toArray()` / `parent::toJson()` in a model that overrides them: the serialisation of the model itself. */
    private function parentOfModel(CallSite $c, EvalContext $ctx): Type
    {
        foreach (array_keys($c->args) as $i) {
            $c->argType($i);
        }
        $self = $ctx->evaluate(new Expr\Variable('this'));
        if (!$self instanceof ModelT) {
            return $ctx->gap(GapCode::RESOURCE_NOT_ANALYZED, $c->node, 'parent::' . $c->name . '() outside a model: what it returns is not known');
        }
        $this->serializer->serialize($self, $c->node, $ctx);

        return new PlainT();
    }
}
