<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Run;

use PeakSim\Runtime\Run\ActionDefinition;
use PeakSim\Runtime\Tests\Unit\Measure\Stubs\AlbumController;
use PeakSim\Runtime\Tests\Unit\Measure\Stubs\EmptyController;
use PeakSim\Runtime\Tests\Unit\Measure\Stubs\InvokableAlbumController;
use PeakSim\Runtime\Tests\Unit\Measure\Stubs\InvokableWithoutDestroyController;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Measure/Stubs/RouteTableStubs.php';

/** A route whose controller has no such operation is told apart by the controller's class, never by hitting it. */
final class ActionDefinitionTest extends TestCase
{
    public function test_a_method_the_controller_has_is_defined(): void
    {
        $this->assertNull(ActionDefinition::undefined(AlbumController::class . '@show'));
    }

    public function test_a_method_the_controller_does_not_have_is_not_defined(): void
    {
        $detail = ActionDefinition::undefined(AlbumController::class . '@destroy');

        $this->assertNotNull($detail);
        $this->assertStringContainsString('destroy', $detail);
        $this->assertStringContainsString(AlbumController::class, $detail);
    }

    public function test_a_class_with_no_methods_defines_nothing(): void
    {
        $this->assertNotNull(ActionDefinition::undefined(EmptyController::class . '@index'));
    }

    public function test_an_invokable_controller_is_defined_by_its_invoke_method(): void
    {
        $this->assertNull(ActionDefinition::undefined(InvokableAlbumController::class));
        $this->assertNull(ActionDefinition::undefined(InvokableAlbumController::class . '@__invoke'));
    }

    public function test_a_named_method_is_not_defined_by_an_invoke_method_the_class_happens_to_have(): void
    {
        // apiResource registers all seven operations; a controller that only has __invoke has none of the named ones.
        $this->assertNotNull(ActionDefinition::undefined(InvokableWithoutDestroyController::class . '@destroy'));
    }

    public function test_a_class_name_alone_is_not_defined_when_the_class_is_not_invokable(): void
    {
        $this->assertNotNull(ActionDefinition::undefined(AlbumController::class));
    }

    public function test_a_method_that_is_not_public_is_not_defined(): void
    {
        $this->assertNotNull(ActionDefinition::undefined(ControllerWithHiddenMethods::class . '@secret'));
        $this->assertNotNull(ActionDefinition::undefined(ControllerWithHiddenMethods::class . '@guarded'));
        $this->assertNull(ActionDefinition::undefined(ControllerWithHiddenMethods::class . '@open'));
    }

    public function test_a_magic_call_does_not_make_a_missing_method_defined(): void
    {
        // Laravel's base Controller answers any missing method with a BadMethodCallException (a 500), so __call
        // must not count as the operation being there.
        $this->assertNotNull(ActionDefinition::undefined(ControllerWithMagicCall::class . '@destroy'));
    }

    public function test_the_method_name_is_matched_whatever_its_case(): void
    {
        // PHP method names are case-insensitive, so the route works and must be hit.
        $this->assertNull(ActionDefinition::undefined(AlbumController::class . '@SHOW'));
    }

    public function test_a_class_that_cannot_be_found_is_not_judged(): void
    {
        // Not seeing a class is not seeing that the operation is missing (an autoload we do not have): hit it as usual.
        $this->assertNull(ActionDefinition::undefined('No\\Such\\Controller@index'));
    }

    public function test_an_action_with_nothing_to_look_at_is_not_judged(): void
    {
        $this->assertNull(ActionDefinition::undefined(null));
        $this->assertNull(ActionDefinition::undefined(''));
        $this->assertNull(ActionDefinition::undefined('Closure'));
    }
}

final class ControllerWithHiddenMethods
{
    public function open(): void
    {
    }

    private function secret(): void
    {
    }

    protected function guarded(): void
    {
    }
}

final class ControllerWithMagicCall
{
    public function index(): void
    {
    }

    /** @param array<int, mixed> $arguments */
    public function __call(string $method, array $arguments): never
    {
        throw new \BadMethodCallException($method);
    }
}
