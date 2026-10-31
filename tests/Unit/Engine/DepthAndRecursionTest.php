<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Engine;

use PeakSim\Analysis\Engine\EngineOptions;
use PeakSim\Model\EventKind;
use PeakSim\Model\GapCode;
use PHPUnit\Framework\Attributes\DataProvider;

/** e-depth-19 / 20 / 21, e-recursion-direct / mutual, poisoned summaries, the budget (design 1.7, 3.4). */
final class DepthAndRecursionTest extends EngineCase
{
    /**
     * A chain of $length classes: the action is level 1 and calls the next class's run(); the last one reads orders.
     *
     * @return array<string, string>
     */
    private function chain(int $length): array
    {
        $files = [];
        for ($i = 1; $i <= $length; $i++) {
            $body = $i === $length
                ? 'return Order::get();'
                : 'return $this->next->run();';
            $ctor = $i === $length ? '' : 'public function __construct(private readonly L' . ($i + 1) . ' $next) {}';
            $files["app/Chain/L{$i}.php"] = "<?php\nnamespace App\\Chain;\nuse App\\Models\\Order;\nclass L{$i}\n{\n    {$ctor}\n    public function run()\n    {\n        {$body}\n    }\n}\n";
        }

        return $files;
    }

    /** @return iterable<string, array{int, bool}> */
    public static function chainLengths(): iterable
    {
        yield '19 levels: well inside' => [19, false];
        yield '20 levels: the last level allowed' => [20, false];
        yield '21 levels: one too many' => [21, true];
        yield '25 levels' => [25, true];
    }

    #[DataProvider('chainLengths')]
    public function testTheTwentiethLevelIsAnalysedAndTheTwentyFirstIsAGap(int $length, bool $truncated): void
    {
        $summary = $this->analyze($this->chain($length), 'App\Chain\L1', 'run');

        if ($truncated) {
            self::assertSame([GapCode::DEPTH_LIMIT], $this->gapCodes($summary));
            self::assertTrue($summary->truncated);
            self::assertSame([], $this->events($summary), 'the method at level 21 is not walked, so its query is not seen');
        } else {
            self::assertSame([], $summary->gaps);
            self::assertFalse($summary->truncated);
            self::assertCount(1, $this->events($summary, EventKind::Select));
        }
        $this->assertNoUnknownWithoutGap();
    }

    public function testTheDepthLimitIsAnOption(): void
    {
        $summary = $this->analyze($this->chain(5), 'App\Chain\L1', 'run', [], new EngineOptions(maxDepth: 3, auditUnknown: true));

        self::assertSame([GapCode::DEPTH_LIMIT], $this->gapCodes($summary));
        self::assertTrue($summary->truncated);
    }

    public function testADepthGapNamesTheCallThatWasNotFollowed(): void
    {
        $summary = $this->analyze($this->chain(22), 'App\Chain\L1', 'run');

        self::assertSame('app/Chain/L20.php', $summary->gaps[0]->site->file, 'the call inside level 20 is the one refused');
    }

    public function testADirectRecursionIsAGapAndTheRestOfTheMethodIsStillSeen(): void
    {
        $summary = $this->analyze([
            'app/Rec.php' => <<<'PHP'
                <?php
                namespace App;
                use App\Models\Order;
                class Rec
                {
                    public function walk()
                    {
                        $orders = Order::get();
                        return $this->walk();
                    }
                }
                PHP,
        ], 'App\Rec', 'walk');

        self::assertSame([GapCode::RECURSION], $this->gapCodes($summary));
        self::assertCount(1, $this->events($summary, EventKind::Select));
        self::assertFalse($summary->truncated, 'recursion is not the depth limit');
        $this->assertNoUnknownWithoutGap();
    }

    public function testAMutualRecursionIsAGap(): void
    {
        $summary = $this->analyze([
            'app/Mutual.php' => <<<'PHP'
                <?php
                namespace App;
                use App\Models\Order;
                class Mutual
                {
                    public function a()
                    {
                        Order::get();
                        return $this->b();
                    }
                    public function b()
                    {
                        Order::first();
                        return $this->a();
                    }
                }
                PHP,
        ], 'App\Mutual', 'a');

        self::assertSame([GapCode::RECURSION], $this->gapCodes($summary));
        self::assertCount(2, $this->events($summary), 'a() and b() were each walked once');
    }

    public function testASummaryWithARecursionGapIsNotReused(): void
    {
        $summary = $this->analyze([
            'app/Twice.php' => <<<'PHP'
                <?php
                namespace App;
                class Twice
                {
                    public function run()
                    {
                        $this->loop();
                        $this->loop();
                    }
                    public function loop()
                    {
                        return $this->loop();
                    }
                }
                PHP,
        ], 'App\Twice', 'run');

        self::assertNotNull($this->engine);
        self::assertSame(0, $this->engine->stats()['summaries_reused'], 'depends on where it was asked, so it is walked again');
        self::assertSame([GapCode::RECURSION], $this->gapCodes($summary));
    }

    public function testASummaryIsNotReusedWhereItsOwnDepthWouldPassTheLimit(): void
    {
        // shallow() is first used at level 2 (fine), then at level 20 where its helper chain reaches level 21.
        $files = [];
        $files['app/Deep/Shallow.php'] = "<?php\nnamespace App\\Deep;\nuse App\\Models\\Order;\nclass Shallow\n{\n    public function __construct(private readonly H1 \$h) {}\n    public function run()\n    {\n        return \$this->h->go();\n    }\n}\n";
        $files['app/Deep/H1.php'] = "<?php\nnamespace App\\Deep;\nuse App\\Models\\Order;\nclass H1\n{\n    public function go()\n    {\n        return Order::get();\n    }\n}\n";
        $files['app/Deep/Top.php'] = "<?php\nnamespace App\\Deep;\nclass Top\n{\n    public function __construct(private readonly Shallow \$shallow, private readonly P2 \$p) {}\n    public function run()\n    {\n        \$this->shallow->run();\n        return \$this->p->run();\n    }\n}\n";
        // P2 .. P19 (levels 2 to 19), then Shallow at level 20, so its helper H1 would be level 21.
        for ($i = 2; $i <= 19; $i++) {
            $next = $i === 19 ? 'Shallow' : 'P' . ($i + 1);
            $files["app/Deep/P{$i}.php"] = "<?php\nnamespace App\\Deep;\nclass P{$i}\n{\n    public function __construct(private readonly {$next} \$n) {}\n    public function run()\n    {\n        return \$this->n->run();\n    }\n}\n";
        }

        $summary = $this->analyze($files, 'App\Deep\Top', 'run');

        // Directly from Top, Shallow is level 2 and H1 level 3: fine, and the summary is cached. Via P2..P19 Shallow is level 20
        // and H1 would be level 21: the cached summary must not be reused there.
        self::assertContains(GapCode::DEPTH_LIMIT, $this->gapCodes($summary));
        self::assertTrue($summary->truncated);
    }

    public function testTheBudgetStopsARunawayAnalysis(): void
    {
        $summary = $this->analyze($this->chain(8), 'App\Chain\L1', 'run', [], new EngineOptions(budget: 3, auditUnknown: true));

        self::assertContains(GapCode::BUDGET_EXCEEDED, $this->gapCodes($summary));
    }
}
