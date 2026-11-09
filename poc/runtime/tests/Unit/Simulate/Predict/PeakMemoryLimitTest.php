<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Simulate\Predict;

use PeakSim\Runtime\Simulate\Predict\MemoryAccuracy;
use PeakSim\Runtime\Simulate\Predict\PeakMemoryLimit;
use PHPUnit\Framework\TestCase;

final class PeakMemoryLimitTest extends TestCase
{
    private const LIMIT = 134217728; // 128M

    public function testTheFirstCountOverEightyPercentOfTheLimitIsFound(): void
    {
        // y = N: the line is 0.8 x 134,217,728 = 107,374,182.4, so the first whole N over it is 107,374,183
        self::assertSame(107374183, PeakMemoryLimit::firstOver(0.0, 1.0, 1.0, 107374182.4));
        // y = 2N + 10, line 100: 2N + 10 > 100 -> N > 45, so 46
        self::assertSame(46, PeakMemoryLimit::firstOver(10.0, 2.0, 1.0, 100.0));
        // y = N^2, line 1,000,000: N > 1000 -> 1001
        self::assertSame(1001, PeakMemoryLimit::firstOver(0.0, 1.0, 2.0, 1000000.0));
    }

    public function testACountThatIsExactlyOnTheLineIsNotOver(): void
    {
        self::assertSame(51, PeakMemoryLimit::firstOver(0.0, 2.0, 1.0, 100.0)); // 2 x 50 = 100 is on the line
    }

    public function testAFormulaAlreadyOverAtTheEmptySystemGivesZeroAndOneThatNeverGrowsGivesNone(): void
    {
        self::assertSame(0, PeakMemoryLimit::firstOver(200.0, 1.0, 1.0, 100.0));
        self::assertNull(PeakMemoryLimit::firstOver(50.0, 0.0, 1.0, 100.0));
        self::assertNull(PeakMemoryLimit::firstOver(50.0, 10.0, 0.0, 100.0)); // k = 0: the constant 60
    }

    /** @return array<string, array{table: string|null, a: float, b: float, k: float}|null> */
    private static function formulas(): array
    {
        return [
            'GET /ok' => ['table' => 'items', 'a' => 0.0, 'b' => 1000.0, 'k' => 1.0],
            'GET /off' => ['table' => 'items', 'a' => 0.0, 'b' => 1000.0, 'k' => 1.0],
            'GET /new' => ['table' => 'items', 'a' => 0.0, 'b' => 1000.0, 'k' => 1.0],
            'GET /none' => null,
        ];
    }

    public function testOnlyARouteWhoseFormulaHeldIsGivenANumberAndTheOthersSayWhy(): void
    {
        $accuracy = MemoryAccuracy::fromArray(['schema_version' => '1', 'within' => ['GET /ok'], 'outside' => ['GET /off']]);
        $out = [];
        foreach (PeakMemoryLimit::all(self::formulas(), $accuracy, self::LIMIT) as $r) {
            $out[$r['route']] = $r;
        }
        self::assertSame(PeakMemoryLimit::SHOWN, $out['GET /ok']['status']);
        self::assertSame(107375, $out['GET /ok']['n']); // 1000 x N > 107,374,182.4 -> N = 107,375 (107,374 x 1000 = 107,374,000 is under)
        self::assertSame('items', $out['GET /ok']['table']);
        self::assertSame(PeakMemoryLimit::NOT_SHOWN, $out['GET /off']['status']);
        self::assertSame(PeakMemoryLimit::REASON_ACCURACY, $out['GET /off']['reason']);
        self::assertNull($out['GET /off']['n']);
        self::assertSame(PeakMemoryLimit::REASON_NOT_CHECKED, $out['GET /new']['reason']);
        self::assertSame(PeakMemoryLimit::REASON_NO_FORMULA, $out['GET /none']['reason']);
        self::assertSame(['GET /new', 'GET /none', 'GET /off', 'GET /ok'], array_keys($out));
    }

    public function testWithNoMemoryLimitNothingIsShown(): void
    {
        $accuracy = MemoryAccuracy::fromArray(['schema_version' => '1', 'within' => ['GET /ok']]);
        foreach (PeakMemoryLimit::all(self::formulas(), $accuracy, null) as $r) {
            self::assertSame(PeakMemoryLimit::NOT_SHOWN, $r['status']);
        }
    }

    public function testNoAccuracyFileMeansNothingWasChecked(): void
    {
        foreach (PeakMemoryLimit::all(self::formulas(), MemoryAccuracy::none(), self::LIMIT) as $r) {
            self::assertSame(PeakMemoryLimit::NOT_SHOWN, $r['status']);
        }
    }

    public function testTheAccuracyFileIsChecked(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MemoryAccuracy::fromArray(['schema_version' => '1', 'within' => ['GET /a'], 'outside' => ['GET /a']]);
    }

    public function testTheAccuracyFileNeedsItsSchemaVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MemoryAccuracy::fromArray(['within' => []]);
    }
}
