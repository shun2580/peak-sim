<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Engine;

use PeakSim\Binding\ProviderBindingTable;
use PeakSim\Model\EventKind;
use PeakSim\Model\GapCode;

/** How the engine follows what BindingTable returns (karo relay: QC ruling on PB). */
final class BindingChainTest extends EngineCase
{
    private const ACT = "<?php\nnamespace App;\nclass Act\n{\n    public function __construct(private readonly A \$a) {}\n    public function run()\n    {\n        return \$this->a->all();\n    }\n}\n";

    /** @return array<string, string> */
    private function files(): array
    {
        return [
            'app/Act.php' => self::ACT,
            'app/A.php' => "<?php\nnamespace App;\ninterface A\n{\n    public function all();\n}\n",
            'app/B.php' => "<?php\nnamespace App;\ninterface B extends A\n{\n}\n",
            'app/Concrete.php' => "<?php\nnamespace App;\nuse App\\Models\\Order;\nclass Concrete implements B\n{\n    public function all()\n    {\n        return Order::get();\n    }\n}\n",
        ];
    }

    public function testABindingThatPointsAtAnotherAbstractIsFollowedToTheConcreteClass(): void
    {
        $summary = $this->analyze($this->files(), 'App\Act', 'run', ['App\A' => ['App\B'], 'App\B' => ['App\Concrete']]);

        self::assertSame([], $summary->gaps);
        self::assertCount(1, $this->events($summary, EventKind::Select), 'A -> B -> Concrete reaches Concrete::all()');
        self::assertSame([], $summary->assumptions);
        $this->assertNoUnknownWithoutGap();
    }

    public function testAChainThatEndsInAnAbstractWithNoBindingIsAGap(): void
    {
        $summary = $this->analyze($this->files(), 'App\Act', 'run', ['App\A' => ['App\B']]);

        self::assertSame([GapCode::NO_BINDING], $this->gapCodes($summary));
        self::assertSame([], $summary->events);
    }

    public function testBindingsThatLeadBackToThemselvesAreAGapNotALoop(): void
    {
        $summary = $this->analyze($this->files(), 'App\Act', 'run', ['App\A' => ['App\B'], 'App\B' => ['App\A']]);

        self::assertSame([GapCode::UNRESOLVABLE_BINDING], $this->gapCodes($summary));
    }

    public function testBothBranchesOfAChainAreWalkedWhenAnAbstractHasSeveralImplementations(): void
    {
        $files = $this->files() + [
            'app/Other.php' => "<?php\nnamespace App;\nuse App\\Models\\Order;\nclass Other implements A\n{\n    public function all()\n    {\n        foreach (Order::get() as \$o) {\n            \$o->customer;\n        }\n    }\n}\n",
        ];
        $summary = $this->analyze($files, 'App\Act', 'run', ['App\A' => ['App\B', 'App\Other'], 'App\B' => ['App\Concrete']]);

        self::assertCount(2, $this->events($summary, EventKind::Select));
        self::assertCount(1, $this->events($summary, EventKind::LazyLoad));
        self::assertCount(1, $summary->assumptions);
    }

    public function testAVendorTypeTheAppBindsToItsOwnClassIsFollowed(): void
    {
        $summary = $this->analyze([
            'app/Act.php' => "<?php\nnamespace App;\nuse Vendor\\Contracts\\Reporter;\nclass Act\n{\n    public function __construct(private readonly Reporter \$r) {}\n    public function run()\n    {\n        return \$this->r->report();\n    }\n}\n",
            'app/MyReporter.php' => "<?php\nnamespace App;\nuse App\\Models\\Order;\nclass MyReporter\n{\n    public function report()\n    {\n        return Order::get();\n    }\n}\n",
        ], 'App\Act', 'run', ['Vendor\Contracts\Reporter' => ['App\MyReporter']]);

        self::assertSame([], $summary->gaps);
        self::assertCount(1, $this->events($summary, EventKind::Select));
    }

    public function testAVendorTypeWithNoBindingIsNotModelledAndIsNotNoBinding(): void
    {
        $summary = $this->analyze([
            'app/Act.php' => "<?php\nnamespace App;\nuse Vendor\\Contracts\\Reporter;\nclass Act\n{\n    public function __construct(private readonly Reporter \$r) {}\n    public function run()\n    {\n        return \$this->r->report();\n    }\n}\n",
        ], 'App\Act', 'run');

        self::assertSame([GapCode::VENDOR_CALL_NOT_MODELLED], $this->gapCodes($summary));
    }

    public function testAnAbstractClassWithTheMethodItselfNeedsNoBinding(): void
    {
        $summary = $this->analyze([
            'app/Act.php' => "<?php\nnamespace App;\nclass Act\n{\n    public function __construct(private readonly Base \$b) {}\n    public function run()\n    {\n        return \$this->b->all();\n    }\n}\n",
            'app/Base.php' => "<?php\nnamespace App;\nuse App\\Models\\Order;\nabstract class Base\n{\n    public function all()\n    {\n        return Order::get();\n    }\n}\n",
        ], 'App\Act', 'run');

        self::assertSame([], $summary->gaps);
        self::assertCount(1, $this->events($summary, EventKind::Select));
    }

    public function testADecoratedBindingIsTheDecoratedGapAndNotTheUnresolvableOne(): void
    {
        $summary = $this->analyze($this->files(), 'App\Act', 'run', ['App\A' => ProviderBindingTable::REASON_DECORATED . ' extend() wraps App\A']);

        self::assertSame([GapCode::BINDING_DECORATED], $this->gapCodes($summary));
        self::assertSame([], $summary->events, 'the implementation behind the interface is not guessed');
        $this->assertNoUnknownWithoutGap();
    }

    public function testADecoratedBindingFurtherDownTheChainIsStillDecorated(): void
    {
        $summary = $this->analyze(
            $this->files(),
            'App\Act',
            'run',
            ['App\A' => ['App\B'], 'App\B' => ProviderBindingTable::REASON_DECORATED . ' afterResolving() hooks App\B'],
        );

        self::assertSame([GapCode::BINDING_DECORATED], $this->gapCodes($summary));
        self::assertSame([], $summary->events);
    }

    public function testAnUnresolvableBindingKeepsItsOwnGap(): void
    {
        $summary = $this->analyze($this->files(), 'App\Act', 'run', ['App\A' => ProviderBindingTable::REASON_UNRESOLVABLE . ' a provider could not be read']);

        self::assertSame([GapCode::UNRESOLVABLE_BINDING], $this->gapCodes($summary));
    }

    public function testAReasonWithoutAKnownPrefixIsUnresolvableNotDecorated(): void
    {
        $summary = $this->analyze($this->files(), 'App\Act', 'run', ['App\A' => 'something else went wrong']);

        self::assertSame([GapCode::UNRESOLVABLE_BINDING], $this->gapCodes($summary));
    }
}
