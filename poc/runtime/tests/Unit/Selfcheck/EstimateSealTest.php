<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Selfcheck;

use PeakSim\Runtime\Selfcheck\EstimateSeal;
use PeakSim\Runtime\Selfcheck\SelfcheckOrderViolation;
use PHPUnit\Framework\TestCase;

/**
 * The order of a self-check: the estimate is sealed first, and a measurement may start only through the gate that checks the seal.
 * Taking the seal or the check away must make these tests fail.
 */
final class EstimateSealTest extends TestCase
{
    /** sha256 of the three bytes "abc" and of "abd": fixed values, not computed by the code under test */
    private const SHA_ABC = 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad';

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/peak-seal-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/estimate.json', 'abc');
        file_put_contents($this->dir . '/marked-routes.json', '{"routes":[]}');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    private static function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time);
    }

    /** @return list<string> */
    private function logLines(): array
    {
        return preg_split('/\R/', trim((string) file_get_contents($this->dir . '/order.log'))) ?: [];
    }

    public function testSealRecordsTheHashOfEveryFileAndStartsTheOrderLog(): void
    {
        $seal = EstimateSeal::seal($this->dir, ['estimate.json', 'marked-routes.json'], self::at('2026-11-09T16:00:00+09:00'));

        self::assertSame(self::SHA_ABC, $seal['sha256']['estimate.json']);
        self::assertSame(hash('sha256', '{"routes":[]}'), $seal['sha256']['marked-routes.json']);
        self::assertSame('2026-11-09T16:00:00+09:00', $seal['sealed_at']);
        self::assertSame($seal, json_decode((string) file_get_contents($this->dir . '/seal.json'), true));
        $lines = $this->logLines();
        self::assertCount(1, $lines);
        self::assertStringStartsWith('estimate_sealed 2026-11-09T16:00:00+09:00 ', $lines[0]);
        self::assertStringContainsString('estimate.json=' . self::SHA_ABC, $lines[0]);
    }

    public function testNothingIsSealedForAFileThatIsNotWrittenYet(): void
    {
        $this->expectException(SelfcheckOrderViolation::class);
        try {
            EstimateSeal::seal($this->dir, ['estimate.json', 'not-yet.json'], self::at('2026-11-09T16:00:00+09:00'));
        } finally {
            self::assertFileDoesNotExist($this->dir . '/seal.json');
            self::assertFileDoesNotExist($this->dir . '/order.log');
        }
    }

    public function testASealIsWrittenOnce(): void
    {
        EstimateSeal::seal($this->dir, ['estimate.json'], self::at('2026-11-09T16:00:00+09:00'));

        $this->expectException(SelfcheckOrderViolation::class);
        EstimateSeal::seal($this->dir, ['estimate.json'], self::at('2026-11-09T16:05:00+09:00'));
    }

    public function testTheMeasurementCannotStartBeforeTheEstimateIsSealed(): void
    {
        try {
            EstimateSeal::beginMeasurement($this->dir, self::at('2026-11-09T16:00:00+09:00'));
            self::fail('the gate opened without a seal');
        } catch (SelfcheckOrderViolation $e) {
            self::assertStringContainsString('not sealed', $e->getMessage());
        }
        // and it leaves no trace that a measurement began
        self::assertFileDoesNotExist($this->dir . '/order.log');
    }

    public function testTheGateOpensAfterTheSealAndRecordsTheStart(): void
    {
        EstimateSeal::seal($this->dir, ['estimate.json', 'marked-routes.json'], self::at('2026-11-09T16:00:00+09:00'));
        EstimateSeal::beginMeasurement($this->dir, self::at('2026-11-09T16:00:07+09:00'));

        $lines = $this->logLines();
        self::assertCount(2, $lines);
        self::assertStringStartsWith('estimate_sealed ', $lines[0]);
        self::assertStringStartsWith('measure_started 2026-11-09T16:00:07+09:00 ', $lines[1]);
        // the start carries the hashes that were checked
        self::assertStringContainsString('estimate.json=' . self::SHA_ABC, $lines[1]);
    }

    public function testASealedFileThatChangedStopsTheMeasurement(): void
    {
        EstimateSeal::seal($this->dir, ['estimate.json', 'marked-routes.json'], self::at('2026-11-09T16:00:00+09:00'));
        file_put_contents($this->dir . '/estimate.json', 'abd');

        try {
            EstimateSeal::beginMeasurement($this->dir, self::at('2026-11-09T16:00:07+09:00'));
            self::fail('the gate opened although a sealed file changed');
        } catch (SelfcheckOrderViolation $e) {
            self::assertStringContainsString('changed after it was sealed: estimate.json', $e->getMessage());
        }
        self::assertCount(1, $this->logLines(), 'no measure_started line was written');
    }

    public function testASealedFileThatIsGoneStopsTheMeasurement(): void
    {
        EstimateSeal::seal($this->dir, ['estimate.json', 'marked-routes.json'], self::at('2026-11-09T16:00:00+09:00'));
        unlink($this->dir . '/marked-routes.json');

        $this->expectException(SelfcheckOrderViolation::class);
        $this->expectExceptionMessage('gone: marked-routes.json');
        EstimateSeal::beginMeasurement($this->dir, self::at('2026-11-09T16:00:07+09:00'));
    }

    public function testTheGateOpensOnlyOnce(): void
    {
        EstimateSeal::seal($this->dir, ['estimate.json'], self::at('2026-11-09T16:00:00+09:00'));
        EstimateSeal::beginMeasurement($this->dir, self::at('2026-11-09T16:00:07+09:00'));

        $this->expectException(SelfcheckOrderViolation::class);
        EstimateSeal::beginMeasurement($this->dir, self::at('2026-11-09T16:30:00+09:00'));
    }

    public function testASealWithoutTheLogLineDoesNotOpenTheGate(): void
    {
        // a seal.json made by hand, with the right hashes but no `estimate_sealed` line first
        file_put_contents($this->dir . '/seal.json', json_encode(['sealed_at' => '2026-11-09T16:00:00+09:00', 'sha256' => ['estimate.json' => self::SHA_ABC]]));

        $this->expectException(SelfcheckOrderViolation::class);
        $this->expectExceptionMessage('does not begin with estimate_sealed');
        EstimateSeal::beginMeasurement($this->dir, self::at('2026-11-09T16:00:07+09:00'));
    }

    public function testTheOrderIsVerifiedForTheJudgeOnlyAfterTheGate(): void
    {
        EstimateSeal::seal($this->dir, ['estimate.json', 'marked-routes.json'], self::at('2026-11-09T16:00:00+09:00'));
        try {
            EstimateSeal::verifyOrder($this->dir);
            self::fail('a record whose measurement did not go through the gate was accepted');
        } catch (SelfcheckOrderViolation $e) {
            self::assertStringContainsString('did not go through the gate', $e->getMessage());
        }

        EstimateSeal::beginMeasurement($this->dir, self::at('2026-11-09T16:00:07+09:00'));
        $order = EstimateSeal::verifyOrder($this->dir);

        self::assertSame('2026-11-09T16:00:00+09:00', $order['sealed_at']);
        self::assertSame('2026-11-09T16:00:07+09:00', $order['measure_started_at']);
    }

    public function testAStartLoggedBeforeTheSealIsRefusedByTheJudge(): void
    {
        $seal = EstimateSeal::seal($this->dir, ['estimate.json'], self::at('2026-11-09T16:00:00+09:00'));
        $h = 'estimate.json=' . $seal['sha256']['estimate.json'];
        file_put_contents($this->dir . '/order.log', "measure_started 2026-11-09T15:59:00+09:00 $h\nestimate_sealed 2026-11-09T16:00:00+09:00 $h\n");

        $this->expectException(SelfcheckOrderViolation::class);
        EstimateSeal::verifyOrder($this->dir);
    }

    public function testAStartDatedBeforeTheSealIsRefusedByTheJudge(): void
    {
        $seal = EstimateSeal::seal($this->dir, ['estimate.json'], self::at('2026-11-09T16:00:00+09:00'));
        $h = 'estimate.json=' . $seal['sha256']['estimate.json'];
        file_put_contents($this->dir . '/order.log', "estimate_sealed 2026-11-09T16:00:00+09:00 $h\nmeasure_started 2026-11-09T15:59:59+09:00 $h\n");

        $this->expectException(SelfcheckOrderViolation::class);
        $this->expectExceptionMessage('dated before');
        EstimateSeal::verifyOrder($this->dir);
    }

    public function testTheJudgeRefusesAnEstimateChangedAfterTheMeasurementStarted(): void
    {
        EstimateSeal::seal($this->dir, ['estimate.json'], self::at('2026-11-09T16:00:00+09:00'));
        EstimateSeal::beginMeasurement($this->dir, self::at('2026-11-09T16:00:07+09:00'));
        file_put_contents($this->dir . '/estimate.json', 'abd');

        $this->expectException(SelfcheckOrderViolation::class);
        $this->expectExceptionMessage('changed after it was sealed');
        EstimateSeal::verifyOrder($this->dir);
    }
}
