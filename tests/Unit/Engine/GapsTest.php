<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Engine;

use PeakSim\Model\EventKind;
use PeakSim\Model\GapCode;

/** Every call the engine cannot follow is a gap, and an UnknownT is made only with one (invariant I1). */
final class GapsTest extends EngineCase
{
    public function testAnUnknownFunctionIsAGapAndItsResultStaysUnknown(): void
    {
        $summary = $this->invokable(<<<'PHP'
                $x = mystery_function();
                $x->foo();
                $x->bar->baz;
                foreach (Order::get() as $order) {
                    $order->customer;
                }
        PHP);

        self::assertSame([GapCode::UNRESOLVED_CALL], $this->gapCodes($summary), 'the unknown value spreads without making a gap per use');
        self::assertCount(2, $summary->events, 'the rest of the method is still read');
        $this->assertNoUnknownWithoutGap();
    }

    public function testNoGapMeansNothingWasMissed(): void
    {
        $summary = $this->invokable('        $a = 1 + 2;');

        self::assertSame([], $summary->gaps);
        self::assertSame([], $summary->events);
    }

    public function testNewOfAClassOutsideTheAppIsAGapButThrowingOneIsNot(): void
    {
        $summary = $this->invokable(<<<'PHP'
                if (Order::first() !== null) {
                    throw new \RuntimeException('too many');
                }
                $thing = new \Vendor\Widget(1);
        PHP);

        self::assertSame([GapCode::NEW_UNKNOWN_CLASS], $this->gapCodes($summary));
        self::assertStringContainsString('Vendor', $summary->gaps[0]->message);
    }

    public function testNewOfADynamicClassNameIsAGap(): void
    {
        $summary = $this->invokable(<<<'PHP'
                $class = 'App\Models\Order';
                $made = new $class();
        PHP);

        self::assertSame([GapCode::DYNAMIC_CLASS_NAME], $this->gapCodes($summary));
    }

    public function testAStaticCallOnADynamicClassIsAGapAndItsArgumentsAreStillRead(): void
    {
        $summary = $this->invokable(<<<'PHP'
                $class = 'App\Models\Order';
                $class::find(Order::first());
        PHP);

        self::assertSame([GapCode::DYNAMIC_CLASS_NAME], $this->gapCodes($summary));
        self::assertCount(1, $this->events($summary, EventKind::Select), 'the Order::first() inside the arguments is not lost');
    }

    public function testADynamicMethodNameIsNotResolvedEvenWhenTheNamesAreAConstant(): void
    {
        $summary = $this->analyze(['app/Http/Controllers/Dyn.php' => <<<'PHP'
            <?php
            namespace App\Http\Controllers;
            class Dyn
            {
                private const ALLOWED = ['summary', 'latest'];
                public function __invoke(string $kind)
                {
                    $method = in_array($kind, self::ALLOWED, true) ? $kind : 'summary';
                    return $this->{$method}();
                }
                private function summary() { return 1; }
                private function latest() { return 2; }
            }
            PHP], 'App\Http\Controllers\Dyn');

        self::assertSame([GapCode::DYNAMIC_METHOD_NAME], $this->gapCodes($summary));
        self::assertSame([], $summary->events, 'found nothing, but it did not follow: that is unknown, not nothing');
    }

    public function testAMethodOnAVendorObjectThatNoHandlerKnowsIsAGap(): void
    {
        $summary = $this->analyze(['app/Http/Controllers/V.php' => <<<'PHP'
            <?php
            namespace App\Http\Controllers;
            use Illuminate\Http\Request;
            class V
            {
                public function __invoke(Request $request)
                {
                    return $request->user();
                }
            }
            PHP], 'App\Http\Controllers\V');

        self::assertSame([GapCode::VENDOR_CALL_NOT_MODELLED], $this->gapCodes($summary));
    }

    public function testAMethodThatDoesNotExistIsAGap(): void
    {
        $summary = $this->analyze([
            'app/Act.php' => "<?php\nnamespace App;\nclass Act\n{\n    public function __construct(private readonly Other \$o) {}\n    public function run()\n    {\n        return \$this->o->missing();\n    }\n}\n",
            'app/Other.php' => "<?php\nnamespace App;\nclass Other\n{\n}\n",
        ], 'App\Act', 'run');

        self::assertSame([GapCode::METHOD_NOT_FOUND], $this->gapCodes($summary));
    }

    public function testAnActionThatDoesNotExistIsAGap(): void
    {
        $summary = $this->analyze(['app/Act.php' => "<?php\nnamespace App;\nclass Act\n{\n}\n"], 'App\Act', 'nope');

        self::assertSame([GapCode::METHOD_NOT_FOUND], $this->gapCodes($summary));
    }

    public function testAnInterfaceWithoutABindingIsAGap(): void
    {
        $summary = $this->analyze([
            'app/Act.php' => "<?php\nnamespace App;\nclass Act\n{\n    public function __construct(private readonly Repo \$r) {}\n    public function run()\n    {\n        return \$this->r->all();\n    }\n}\n",
            'app/Repo.php' => "<?php\nnamespace App;\ninterface Repo\n{\n    public function all();\n}\n",
        ], 'App\Act', 'run');

        self::assertSame([GapCode::NO_BINDING], $this->gapCodes($summary));
    }

    public function testAnUndefinedVariableIsAGapNotASilentPlainValue(): void
    {
        $summary = $this->invokable('        return $nowhere->thing;');

        self::assertSame([GapCode::UNRESOLVED_CALL], $this->gapCodes($summary));
    }

    public function testAGeneratorIsAGap(): void
    {
        $summary = $this->invokable(<<<'PHP'
                foreach (Order::get() as $order) {
                    yield $order;
                }
        PHP);

        self::assertContains(GapCode::GENERATOR_NOT_ANALYZED, $this->gapCodes($summary));
    }

    public function testAGapInsideALoopRemembersHowDeepItIs(): void
    {
        $summary = $this->invokable(<<<'PHP'
                foreach (Order::get() as $order) {
                    mystery_function($order);
                }
        PHP);

        self::assertSame(1, $summary->gaps[0]->loopDepth);
        self::assertSame([], $summary->events[0]->frames, 'the Order::get() itself is outside the loop');
    }

    public function testAGapInACalleeKeepsItsDepthPlusTheCallersLoops(): void
    {
        $summary = $this->analyze([
            'app/Act.php' => "<?php\nnamespace App;\nuse App\\Models\\Order;\nclass Act\n{\n    public function run()\n    {\n        foreach (Order::get() as \$o) {\n            \$this->inner();\n        }\n    }\n    private function inner()\n    {\n        mystery_function();\n    }\n}\n",
        ], 'App\Act', 'run');

        self::assertSame(1, $summary->gaps[0]->loopDepth);
    }

    public function testWhatTheEngineNeverDoesIsMakeAnUnknownOutsideGap(): void
    {
        $offenders = [];
        foreach (glob(dirname(__DIR__, 3) . '/src/Analysis/Engine/*.php') ?: [] as $file) {
            if (str_contains((string) file_get_contents($file), 'new UnknownT')) {
                $offenders[] = basename($file);
            }
        }

        self::assertSame(['Engine.php'], $offenders, 'only Engine::issueUnknown() (called by gap()) may create one');
    }
}
