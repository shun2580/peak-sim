<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Engine;

use PeakSim\Model\AssumptionCode;
use PeakSim\Model\Axis;
use PeakSim\Model\EventKind;
use PeakSim\Model\GapCode;

/** e-union-if / e-union-ternary and the worst case over several bindings (design 1.4, 1.8). */
final class BranchesTest extends EngineCase
{
    public function testAfterAnIfTheVariableMayHoldEitherBranchAndTheWorseOneIsAdopted(): void
    {
        $summary = $this->invokable(<<<'PHP'
                if (isset($_GET['all'])) {
                    $orders = Order::get();
                } else {
                    $orders = [];
                }
                foreach ($orders as $order) {
                    $order->customer;
                }
        PHP);

        $lazy = $this->events($summary, EventKind::LazyLoad);
        self::assertCount(1, $lazy);
        self::assertSame('orders', $lazy[0]->frames[0]->origin->table);
        self::assertSame([AssumptionCode::BRANCH_WORST_CASE], array_map(static fn ($a) => $a->code, $summary->assumptions));
        self::assertSame([], $summary->gaps);
    }

    public function testAnEagerLoadInOnlyOneBranchCountsAsNotLoaded(): void
    {
        $summary = $this->invokable(<<<'PHP'
                if (isset($_GET['fast'])) {
                    $order = Order::with('customer')->first();
                } else {
                    $order = Order::first();
                }
                $order->customer;
        PHP);

        self::assertCount(1, $this->events($summary, EventKind::LazyLoad), 'the relation was loaded on one path only');
        self::assertSame([AssumptionCode::BRANCH_WORST_CASE], array_map(static fn ($a) => $a->code, $summary->assumptions));
    }

    public function testATernaryJoinsTheSameWay(): void
    {
        $summary = $this->invokable(<<<'PHP'
                $orders = isset($_GET['all']) ? Order::get() : [];
                foreach ($orders as $order) {
                    $order->customer;
                }
        PHP);

        self::assertCount(1, $this->events($summary, EventKind::LazyLoad));
        self::assertSame(AssumptionCode::BRANCH_WORST_CASE, $summary->assumptions[0]->code);
    }

    public function testNothingIsAssumedWhenBothBranchesAgree(): void
    {
        $summary = $this->invokable(<<<'PHP'
                if (isset($_GET['x'])) {
                    $orders = Order::get();
                } else {
                    $orders = Order::where('a', 1)->get();
                }
                foreach ($orders as $order) {
                    $order->customer;
                }
        PHP);

        self::assertSame([], $summary->assumptions);
    }

    public function testABranchThatReturnsDoesNotReachTheCodeAfterIt(): void
    {
        $summary = $this->invokable(<<<'PHP'
                $order = Order::with('customer')->first();
                if (isset($_GET['stop'])) {
                    $order = Order::first();
                    return 1;
                }
                $order->customer;
        PHP);

        self::assertSame([], $this->events($summary, EventKind::LazyLoad), 'only the path that carries on matters after the if');
    }

    public function testDifferentKindsStaySideBySideAndBothAreCalled(): void
    {
        $summary = $this->analyze([
            'app/Act.php' => "<?php\nnamespace App;\nclass Act\n{\n    public function run(bool \$f)\n    {\n        \$thing = \$f ? new Foo() : new Bar();\n        return \$thing->go();\n    }\n}\n",
            'app/Foo.php' => "<?php\nnamespace App;\nuse App\\Models\\Order;\nclass Foo\n{\n    public function go()\n    {\n        return Order::first();\n    }\n}\n",
            'app/Bar.php' => "<?php\nnamespace App;\nuse App\\Models\\Order;\nclass Bar\n{\n    public function go()\n    {\n        return Order::get();\n    }\n}\n",
        ], 'App\Act', 'run');

        self::assertCount(2, $this->events($summary, EventKind::Select), 'both candidates are walked');
        self::assertSame([], $summary->gaps);
        $this->assertNoUnknownWithoutGap();
    }

    public function testSeveralBindingsAreAllWalkedAndTheWorstCaseIsAssumedWhenTheyDiffer(): void
    {
        $files = [
            'app/Act.php' => "<?php\nnamespace App;\nclass Act\n{\n    public function __construct(private readonly Summaries \$s) {}\n    public function run()\n    {\n        return \$this->s->all();\n    }\n}\n",
            'app/Summaries.php' => "<?php\nnamespace App;\ninterface Summaries\n{\n    public function all();\n}\n",
            'app/Eager.php' => "<?php\nnamespace App;\nuse App\\Models\\Order;\nclass Eager implements Summaries\n{\n    public function all()\n    {\n        foreach (Order::with('customer')->get() as \$o) {\n            \$o->customer;\n        }\n    }\n}\n",
            'app/Lazy.php' => "<?php\nnamespace App;\nuse App\\Models\\Order;\nclass Lazy implements Summaries\n{\n    public function all()\n    {\n        foreach (Order::get() as \$o) {\n            \$o->customer;\n        }\n    }\n}\n",
        ];
        $summary = $this->analyze($files, 'App\Act', 'run', ['App\Summaries' => ['App\Eager', 'App\Lazy']]);

        self::assertCount(1, $this->events($summary, EventKind::LazyLoad), 'the Lazy implementation');
        self::assertCount(1, $this->events($summary, EventKind::EagerLoad), 'and the Eager one is in the union too');
        self::assertCount(1, $summary->assumptions);
        $assumption = $summary->assumptions[0];
        self::assertSame(AssumptionCode::WORST_CASE_OF_CANDIDATES, $assumption->code);
        self::assertSame([Axis::Query], $assumption->affects, 'they differ in queries only (both load all orders)');
        self::assertSame('App\Lazy', $assumption->candidates['chosen']);
        self::assertSame(['query' => 1, 'memory' => 1], $this->degrees($summary));
    }

    public function testSeveralBindingsThatCostTheSameAssumeNothing(): void
    {
        $files = [
            'app/Act.php' => "<?php\nnamespace App;\nclass Act\n{\n    public function __construct(private readonly Summaries \$s) {}\n    public function run()\n    {\n        return \$this->s->all();\n    }\n}\n",
            'app/Summaries.php' => "<?php\nnamespace App;\ninterface Summaries\n{\n    public function all();\n}\n",
            'app/A.php' => "<?php\nnamespace App;\nuse App\\Models\\Order;\nclass A implements Summaries\n{\n    public function all()\n    {\n        return Order::get();\n    }\n}\n",
            'app/B.php' => "<?php\nnamespace App;\nuse App\\Models\\Order;\nclass B implements Summaries\n{\n    public function all()\n    {\n        return Order::get();\n    }\n}\n",
        ];
        $summary = $this->analyze($files, 'App\Act', 'run', ['App\Summaries' => ['App\A', 'App\B']]);

        self::assertSame([], $summary->assumptions);
    }

    public function testAUnionWithTooManyMembersIsAGap(): void
    {
        $classes = [];
        for ($i = 1; $i <= 9; $i++) {
            $classes["app/K{$i}.php"] = "<?php\nnamespace App;\nclass K{$i}\n{\n}\n";
        }
        $expr = 'new K1()';
        for ($i = 2; $i <= 9; $i++) {
            $expr = "\$f ? new K{$i}() : ({$expr})";
        }
        $classes['app/Act.php'] = "<?php\nnamespace App;\nclass Act\n{\n    public function run(bool \$f)\n    {\n        \$x = {$expr};\n        return \$x;\n    }\n}\n";

        $summary = $this->analyze($classes, 'App\Act', 'run');

        self::assertContains(GapCode::UNION_TOO_LARGE, $this->gapCodes($summary));
    }
}
