<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\LabScore;

use PeakSim\Lab\Score\LadderMeasurements;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../lab/score/LadderMeasurements.php';

/** The ladder record of one scenario read into measured stages: four items, copied as they are. */
final class LadderMeasurementsTest extends TestCase
{
    private static function rung(string $scenario, float $rps, bool $valid, float $util): array
    {
        return ['scenario' => $scenario, 'rung' => 1, 'decided_rps' => $rps, 'achieved_rps' => $rps, 'rps_valid' => $valid,
            'db_utilization' => $util, 'db_utilization_percent' => $util * 100, 'decision' => ['stop' => false]];
    }

    public function test_the_four_items_are_copied_per_scenario_in_the_order_of_the_record(): void
    {
        $m = LadderMeasurements::fromLadders([
            ['scenario' => 't-1', 'rungs' => [self::rung('t-1', 0.05, true, 0.1), self::rung('t-1', 0.07, false, 0.2)]],
            ['scenario' => 't-4', 'rungs' => []],
        ]);
        $this->assertSame(['t-1', 't-4'], array_keys($m));
        $this->assertSame([['rps' => 0.05, 'valid' => true, 'utilization' => 0.1], ['rps' => 0.07, 'valid' => false, 'utilization' => 0.2]], $m['t-1']);
        $this->assertSame([], $m['t-4']);
    }

    /** @param callable(): list<array<string, mixed>> $make */
    #[DataProvider('bad')]
    public function test_a_record_that_does_not_fit_is_refused(callable $make): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LadderMeasurements::fromLadders($make());
    }

    /** @return array<string, array{callable}> */
    public static function bad(): array
    {
        $ok = static fn (): array => ['scenario' => 't-1', 'rungs' => [self::rung('t-1', 0.05, true, 0.1)]];

        return [
            'no scenario' => [static fn (): array => [['rungs' => []]]],
            'no rungs' => [static fn (): array => [['scenario' => 't-1']]],
            'the same scenario twice' => [static fn (): array => [$ok(), $ok()]],
            'a step of another scenario' => [static fn (): array => [['scenario' => 't-1', 'rungs' => [self::rung('t-2', 0.05, true, 0.1)]]]],
            'no decided rps' => [static function () use ($ok): array {
                $l = $ok();
                unset($l['rungs'][0]['decided_rps']);

                return [$l];
            }],
            'valid not a boolean' => [static function () use ($ok): array {
                $l = $ok();
                $l['rungs'][0]['rps_valid'] = 'valid';

                return [$l];
            }],
            'utilization as text' => [static function () use ($ok): array {
                $l = $ok();
                $l['rungs'][0]['db_utilization'] = '10%';

                return [$l];
            }],
        ];
    }
}
