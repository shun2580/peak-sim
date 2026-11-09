<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\LabScore;

use PeakSim\Lab\Score\LadderInput;
use PeakSim\Lab\Score\LadderScore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../lab/score/LadderScore.php';
require_once __DIR__ . '/../../../../lab/score/LadderInput.php';

/** The join of the prediction file and the measured stages: copies what it is given, and refuses what does not fit. */
final class LadderInputTest extends TestCase
{
    /** @return array<string, mixed> a prediction file in the form of the ladder prediction, with a small ladder */
    private static function prediction(): array
    {
        $row = static fn (float $rps, float $point, float $upper, float $naive): array => [
            'rps_total' => $rps,
            'rps' => ['GET /a' => $rps],
            'utilization' => ['point' => $point, 'upper' => $upper],
            'naive' => $naive,
            'over_threshold' => ['point' => false, 'upper' => false, 'naive' => false],
        ];

        return [
            'schema_version' => '0.1',
            'kind' => 'lab-ladder-prediction',
            'resources' => ['cores' => 2.0, 'threshold' => 0.8],
            'stages_rps_total' => [0.1, 0.2, 0.3],
            'scenarios' => [
                [
                    'id' => 't-1',
                    'stages' => [$row(0.1, 0.20, 0.25, 0.30), $row(0.2, 0.40, 0.50, 0.60), $row(0.3, 0.60, 0.75, 0.90)],
                    'limit_rps' => [
                        'point' => ['status' => 'found', 'rps' => 0.4],
                        'safe' => ['status' => 'found', 'rps' => 0.32],
                        'naive' => ['status' => 'found', 'rps' => 0.27],
                    ],
                ],
                [
                    'id' => 't-4',
                    'stages' => [$row(0.1, 0.0, 0.0, 0.30), $row(0.2, 0.0, 0.0, 0.60), $row(0.3, 0.0, 0.0, 0.90)],
                    'limit_rps' => [
                        'point' => ['status' => 'no_load', 'rps' => null],
                        'safe' => ['status' => 'no_load', 'rps' => null],
                        'naive' => ['status' => 'found', 'rps' => 0.27],
                    ],
                ],
            ],
        ];
    }

    public function test_it_copies_the_prediction_and_the_measurement_into_the_form_of_the_score(): void
    {
        $in = LadderInput::join(self::prediction(), [
            't-1' => [['rps' => 0.2, 'valid' => true, 'utilization' => 0.41], ['rps' => 0.1, 'valid' => false, 'utilization' => 0.18]],
        ]);
        $this->assertSame(0.8, $in['threshold']);
        $this->assertSame(['t-1', 't-4'], array_column($in['scenarios'], 'id'));
        $first = $in['scenarios'][0];
        $this->assertSame(
            ['rps' => 0.1, 'predicted' => ['point' => 0.20, 'safe' => 0.25, 'naive' => 0.30], 'measured' => ['valid' => false, 'utilization' => 0.18]],
            $first['stages'][0],
        );
        $this->assertSame(['valid' => true, 'utilization' => 0.41], $first['stages'][1]['measured']);
        $this->assertNull($first['stages'][2]['measured'], 'a stage with no measurement is not measured');
        $this->assertSame(['point' => 0.4, 'safe' => 0.32, 'naive' => 0.27], $first['predicted_limit_rps']);
    }

    public function test_a_scenario_with_no_measurement_is_kept_with_every_stage_not_measured_and_no_load_is_a_null_limit(): void
    {
        $in = LadderInput::join(self::prediction(), ['t-1' => []]);
        $second = $in['scenarios'][1];
        $this->assertSame([null, null, null], array_column($second['stages'], 'measured'));
        $this->assertSame(['point' => null, 'safe' => null, 'naive' => 0.27], $second['predicted_limit_rps']);
    }

    public function test_the_joined_input_is_the_input_the_score_reads(): void
    {
        $in = LadderInput::join(self::prediction(), [
            't-1' => [['rps' => 0.1, 'valid' => true, 'utilization' => 0.2], ['rps' => 0.2, 'valid' => true, 'utilization' => 0.4], ['rps' => 0.3, 'valid' => true, 'utilization' => 0.85]],
        ]);
        $r = LadderScore::score($in);
        $this->assertSame(3, $r['counts']['stages_measured']);
        $this->assertSame('t-1', $r['scenarios'][0]['id']);
        $this->assertSame('interpolated', $r['scenarios'][0]['limit']['measured']['method']);
        $this->assertSame('not_measured', $r['scenarios'][1]['limit']['measured']['status']);
    }

    public function test_the_decided_rps_is_matched_to_the_ladder_step_to_floating_point(): void
    {
        $in = LadderInput::join(self::prediction(), ['t-1' => [['rps' => 0.1 + 1e-13, 'valid' => true, 'utilization' => 0.2]]]);
        $this->assertSame(['valid' => true, 'utilization' => 0.2], $in['scenarios'][0]['stages'][0]['measured']);
    }

    /** @param callable(): array{0: array<string, mixed>, 1: array<string, mixed>} $make */
    #[DataProvider('mismatches')]
    public function test_what_does_not_fit_is_refused_and_never_dropped(callable $make): void
    {
        [$prediction, $measured] = $make();
        $this->expectException(\InvalidArgumentException::class);
        LadderInput::join($prediction, $measured);
    }

    /** @return array<string, array{callable}> */
    public static function mismatches(): array
    {
        $m = static fn (float $rps): array => ['rps' => $rps, 'valid' => true, 'utilization' => 0.3];

        return [
            'a measured stage that is not on the ladder' => [static fn (): array => [self::prediction(), ['t-1' => [$m(0.15)]]]],
            'a measured scenario with no prediction' => [static fn (): array => [self::prediction(), ['t-9' => [$m(0.1)]]]],
            'a stage measured twice' => [static fn (): array => [self::prediction(), ['t-1' => [$m(0.1), $m(0.1)]]]],
            'no valid flag' => [static fn (): array => [self::prediction(), ['t-1' => [['rps' => 0.1, 'utilization' => 0.3]]]]],
            'valid not a boolean' => [static fn (): array => [self::prediction(), ['t-1' => [['rps' => 0.1, 'valid' => 1, 'utilization' => 0.3]]]]],
            'wrong kind' => [static function (): array {
                $p = self::prediction();
                $p['kind'] = 'prediction';

                return [$p, []];
            }],
            'no threshold' => [static function (): array {
                $p = self::prediction();
                unset($p['resources']['threshold']);

                return [$p, []];
            }],
            'no scenario' => [static function (): array {
                $p = self::prediction();
                $p['scenarios'] = [];

                return [$p, []];
            }],
            'a stage without the safe side' => [static function (): array {
                $p = self::prediction();
                unset($p['scenarios'][0]['stages'][0]['utilization']['upper']);

                return [$p, []];
            }],
            'a limit with an unknown status' => [static function (): array {
                $p = self::prediction();
                $p['scenarios'][0]['limit_rps']['point'] = ['status' => 'maybe', 'rps' => 1.0];

                return [$p, []];
            }],
            'a found limit with no number' => [static function (): array {
                $p = self::prediction();
                $p['scenarios'][0]['limit_rps']['point'] = ['status' => 'found', 'rps' => null];

                return [$p, []];
            }],
        ];
    }
}
