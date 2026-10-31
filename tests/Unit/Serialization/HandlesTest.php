<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Serialization;

use PeakSim\Analysis\Contracts\CallHandler;
use PeakSim\Analysis\Contracts\CallSite;
use PeakSim\Analysis\Serialization\AttributeCasts;
use PeakSim\Analysis\Serialization\ResourceHandler;
use PeakSim\Analysis\Serialization\ResponseSink;
use PeakSim\Analysis\Serialization\Serializer;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\ObjectT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\ResourceT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Model\Cardinality;
use PeakSim\Source\AppSourceIndex;
use PeakSim\Tests\Support\FakeEvalContext;
use PHPUnit\Framework\TestCase;
use PhpParser\Node;
use PhpParser\Node\Expr;

/**
 * What each handler claims and what it leaves alone. A handler that takes a call it does not understand would hide
 * it from the engine's own gap, so the refusals matter as much as the acceptances.
 */
final class HandlesTest extends TestCase
{
    private const M = 'App\\Models\\';
    private const R = 'App\\Http\\Resources\\';

    private function ctx(): FakeEvalContext
    {
        return new FakeEvalContext(null, null, null, null, new AppSourceIndex(__DIR__ . '/App'));
    }

    private function fqcn(string $n): Node\Name
    {
        return new Node\Name\FullyQualified(ltrim($n, '\\'));
    }

    private function method(?Type $receiver, string $name): CallSite
    {
        return new CallSite(new Expr\MethodCall(new Expr\Variable('x'), $name), $receiver, null, $name, []);
    }

    private function staticCall(string $class, string $name): CallSite
    {
        return new CallSite(new Expr\StaticCall($this->fqcn($class), $name), null, $class, $name, []);
    }

    private function func(string $name): CallSite
    {
        return new CallSite(new Expr\FuncCall(new Node\Name($name)), null, null, $name, []);
    }

    private function new(string $class): CallSite
    {
        return new CallSite(new Expr\New_($this->fqcn($class)), null, $class, '__construct', []);
    }

    private function sink(): CallHandler
    {
        return new ResponseSink(new Serializer());
    }

    private function resources(): CallHandler
    {
        return new ResourceHandler(new Serializer());
    }

    private function orders(): ListT
    {
        return new ListT(new ModelT(self::M . 'AppendedOrder'), Cardinality::table('orders'));
    }

    public function testTheSinkTakesTheCallsThatTurnAValueIntoJson(): void
    {
        $ctx = $this->ctx();
        $factory = new ObjectT('Illuminate\Contracts\Routing\ResponseFactory');

        $this->assertTrue($this->sink()->handles($this->func('json_encode'), $ctx));
        $this->assertTrue($this->sink()->handles($this->method($factory, 'json'), $ctx));
        $this->assertTrue($this->sink()->handles($this->staticCall('Illuminate\Support\Facades\Response', 'json'), $ctx));
        $this->assertTrue($this->sink()->handles($this->method($this->orders(), 'toArray'), $ctx));
        $this->assertTrue($this->sink()->handles($this->method(new ModelT(self::M . 'AppendedOrder'), 'toJson'), $ctx));
        $this->assertTrue($this->sink()->handles($this->method(new ResourceT(self::R . 'OrderResource', new ModelT(self::M . 'Order'), true), 'resolve'), $ctx));
    }

    public function testTheSinkLeavesEverythingElseAlone(): void
    {
        $ctx = $this->ctx();
        $factory = new ObjectT('Illuminate\Contracts\Routing\ResponseFactory');

        $this->assertFalse($this->sink()->handles($this->func('json_decode'), $ctx));
        $this->assertFalse($this->sink()->handles($this->method($factory, 'noContent'), $ctx));
        $this->assertFalse($this->sink()->handles($this->method(new ObjectT('App\\Other'), 'json'), $ctx));
        $this->assertFalse($this->sink()->handles($this->method($this->orders(), 'count'), $ctx));
        $this->assertFalse($this->sink()->handles($this->method(new PlainT(), 'toArray'), $ctx));
        $this->assertFalse($this->sink()->handles($this->staticCall(self::M . 'Order', 'get'), $ctx));
        $this->assertFalse($this->sink()->handles($this->method(new ResourceT(self::R . 'OrderResource', new ModelT(self::M . 'Order')), 'additional'), $ctx));
    }

    public function testAModelThatDeclaresItsOwnToArrayIsLeftToTheEngine(): void
    {
        // The test app's Order does not declare toArray(); the sink takes it. (A model that did would be the app's code.)
        $this->assertTrue($this->sink()->handles($this->method(new ModelT(self::M . 'Order'), 'toArray'), $this->ctx()));
    }

    public function testTheResourceHandlerTakesResourceConstructorsAndTheirProxyCalls(): void
    {
        $ctx = $this->ctx();
        $proxy = new ResourceT(self::R . 'OrderResource', new ModelT(self::M . 'Order'));

        $this->assertTrue($this->resources()->handles($this->staticCall(self::R . 'OrderResource', 'collection'), $ctx));
        $this->assertTrue($this->resources()->handles($this->staticCall(self::R . 'OrderResource', 'make'), $ctx));
        $this->assertTrue($this->resources()->handles($this->new(self::R . 'OrderResource'), $ctx));
        $this->assertTrue($this->resources()->handles($this->new(self::R . 'OrderCollection'), $ctx));
        $this->assertTrue($this->resources()->handles($this->method($proxy, 'whenLoaded'), $ctx));
        $this->assertTrue($this->resources()->handles(new CallSite(new Expr\PropertyFetch(new Expr\Variable('this'), 'customer'), $proxy, null, 'customer', []), $ctx));
        $this->assertTrue($this->resources()->handles($this->staticCall('Illuminate\Http\Resources\Json\JsonResource', 'toArray'), $ctx));
    }

    public function testTheResourceHandlerLeavesTheSinkCallsAndOtherClassesAlone(): void
    {
        $ctx = $this->ctx();
        $proxy = new ResourceT(self::R . 'OrderResource', new ModelT(self::M . 'Order'));

        $this->assertFalse($this->resources()->handles($this->method($proxy, 'resolve'), $ctx), 'the sink owns resolve()');
        $this->assertFalse($this->resources()->handles($this->staticCall(self::M . 'Order', 'collection'), $ctx), 'a model is not a resource');
        $this->assertFalse($this->resources()->handles($this->new(self::M . 'Order'), $ctx));
        $this->assertFalse($this->resources()->handles($this->staticCall(self::R . 'OrderResource', 'withoutWrapping'), $ctx));
        $this->assertFalse($this->resources()->handles($this->method(new ModelT(self::M . 'Order'), 'whenLoaded'), $ctx), 'only a resource has whenLoaded');
        $this->assertFalse($this->resources()->handles($this->staticCall(self::R . 'OrderResource', 'toArray'), $ctx), 'OrderResource declares its own toArray');
    }

    public function testAttributeCastsTakesOnlyTheAttributeFactoryCalls(): void
    {
        $ctx = $this->ctx();
        $casts = new AttributeCasts();
        $attribute = 'Illuminate\Database\Eloquent\Casts\Attribute';

        $this->assertTrue($casts->handles($this->staticCall($attribute, 'get'), $ctx));
        $this->assertTrue($casts->handles($this->staticCall($attribute, 'make'), $ctx));
        $this->assertTrue($casts->handles($this->new($attribute), $ctx));
        $this->assertFalse($casts->handles($this->staticCall($attribute, 'something'), $ctx));
        $this->assertFalse($casts->handles($this->staticCall(self::M . 'Order', 'get'), $ctx));
        $this->assertFalse($casts->handles($this->method(new ModelT(self::M . 'Order'), 'get'), $ctx));
    }
}
