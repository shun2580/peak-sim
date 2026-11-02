<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Benchmarks;

use PeakSim\Benchmarks\RealApps\RuntimeScorer;
use PeakSim\Benchmarks\RealApps\Truth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/benchmarks/real-apps/lib/Truth.php';
require_once dirname(__DIR__, 3) . '/benchmarks/real-apps/lib/RuntimeScorer.php';

/**
 * The scoring of a runtime measurement against the fixed answers (classes A to E), and the loading of the answers.
 */
final class RealAppsRuntimeScorerTest extends TestCase
{
    /**
     * @param array<string, mixed> $record
     * @param list<array<string, mixed>> $attempts
     */
    #[DataProvider('classCases')]
    public function testAMeasurementFallsIntoTheRightClass(string $group, array $record, array $attempts, string $expected): void
    {
        $this->assertSame($expected, RuntimeScorer::classify($group, $record, $attempts)['class']);
    }

    /** @return array<string, array{string, array<string, mixed>, list<array<string, mixed>>, string}> */
    public static function classCases(): array
    {
        $ok = ['class' => 'ok', 'reason' => 'ok'];
        $grow = [self::attempt('N', 10, 100), self::attempt('N', 100, 400)];

        return [
            'heavy by the code, growth measured' => ['ON', $ok + ['verdict' => 'measured_ON'], $grow, 'A'],
            'heavy by the code, nested growth measured' => ['ON', $ok + ['verdict' => 'measured_ONM'], $grow, 'A'],
            'heavy by the code, O(1) measured' => ['ON', $ok + ['verdict' => 'measured_O1'], $grow, 'C'],
            'heavy by the code, no information' => ['ON', $ok + ['verdict' => 'no_info', 'reason' => 'suspect_empty'], $grow, 'B'],
            'heavy by the code, not measured' => ['ON', ['class' => 'exception', 'reason_detail' => 'boom', 'verdict' => 'not_measured'], [], 'B'],
            'heavy by the code, a verdict from an unreached response' => ['ON', ['class' => 'unauthenticated', 'verdict' => 'measured_ON'], $grow, 'B'],
            'light by the code, O(1) measured' => ['O1', $ok + ['verdict' => 'measured_O1'], $grow, 'D'],
            'conditional, growth measured' => ['COND', $ok + ['verdict' => 'measured_ON'], $grow, 'D'],
            'conditional, nothing measured' => ['COND', ['class' => 'unauthenticated', 'verdict' => 'not_measured'], [], 'D_candidate'],
            'light by the code, verdict from an unreached response' => ['O1', ['class' => 'unfilled_param', 'verdict' => 'measured_O1'], $grow, 'D_candidate'],
            'the code answer is not firm' => ['E', $ok + ['verdict' => 'measured_ON'], $grow, 'E'],
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @param list<array<string, mixed>> $attempts
     */
    #[DataProvider('dKindCases')]
    public function testDIsSplitIntoConfirmedAndDoubtful(string $group, array $record, array $attempts, ?string $expected): void
    {
        $this->assertSame($expected, RuntimeScorer::classify($group, $record, $attempts)['d_kind']);
    }

    /** @return array<string, array{string, array<string, mixed>, list<array<string, mixed>>, string|null}> */
    public static function dKindCases(): array
    {
        $ok = ['class' => 'ok', 'reason' => 'ok'];
        $grow = [self::attempt('N', 10, 100), self::attempt('N', 100, 400)];
        $flat = [self::attempt('N', 10, 346), self::attempt('N', 100, 346)];

        return [
            'light by the code, O(1) measured' => ['O1', $ok + ['verdict' => 'measured_O1'], $flat, 'confirmed'],
            'conditional, growth measured' => ['COND', $ok + ['verdict' => 'measured_ON'], $grow, 'confirmed'],
            'conditional, O(1) measured, response size identical at every scale' => ['COND', $ok + ['verdict' => 'measured_O1'], $flat, 'doubtful'],
            'conditional, growth measured, but the response size never changed' => ['COND', $ok + ['verdict' => 'measured_ON'], $flat, 'doubtful'],
            'conditional, O(1) measured, the response size changed' => ['COND', $ok + ['verdict' => 'measured_O1'], $grow, 'doubtful'],
            'light by the code, growth measured' => ['O1', $ok + ['verdict' => 'measured_ON'], $grow, 'doubtful'],
            'not a D: nothing measured' => ['COND', ['class' => 'unauthenticated', 'verdict' => 'not_measured'], [], null],
            'not a D: heavy by the code' => ['ON', $ok + ['verdict' => 'measured_ON'], $grow, null],
        ];
    }

    public function testTheSummaryCountsConfirmedAndDoubtfulWithoutMovingTheRatio(): void
    {
        $truth = $this->truth([['O(N)', 'high'], ['O(1)', 'high'], ['conditional', 'medium'], ['conditional', 'medium']]);
        $flat = [self::attempt('N', 10, 50), self::attempt('N', 100, 50)];
        $records = [
            ['route' => '/r0', 'method' => 'GET', 'class' => 'ok', 'verdict' => 'measured_ON'],
            ['route' => '/r1', 'method' => 'GET', 'class' => 'ok', 'verdict' => 'measured_O1'],
            ['route' => '/r2', 'method' => 'GET', 'class' => 'ok', 'verdict' => 'measured_O1'],
            ['route' => '/r3', 'method' => 'GET', 'class' => 'unauthenticated', 'verdict' => 'not_measured'],
        ];
        $attempts = [];
        foreach ([1, 2] as $i) {
            foreach ($flat as $a) {
                $attempts[] = $a + ['route' => '/r' . $i, 'method' => 'GET'];
            }
        }

        $s = RuntimeScorer::score($truth, 'a', $records, $attempts)['summary'];

        $this->assertSame(2, $s['counts']['D']);
        $this->assertSame(1, $s['d_confirmed']);
        $this->assertSame(1, $s['d_doubtful']);
        $this->assertSame(1, $s['counts']['D_candidate'], 'D_candidate is not part of D');
        $this->assertSame(1, $s['denominator']);
    }

    public function testAFalseO1IsMarkedWhenTheResponseNeverChanged(): void
    {
        $record = ['class' => 'ok', 'verdict' => 'measured_O1'];
        $flat = [self::attempt('N', 10, 346), self::attempt('N', 100, 346), self::attempt('M', 3, 346)];
        $grows = [self::attempt('N', 10, 346), self::attempt('N', 100, 900)];

        $this->assertTrue(RuntimeScorer::classify('ON', $record, $flat)['bytes_flat']);
        $this->assertFalse(RuntimeScorer::classify('ON', $record, $grows)['bytes_flat']);
    }

    public function testResponseSizeNeedsTwoAnsweringPointsAndOnlyTheFirstAttemptCounts(): void
    {
        $this->assertFalse(RuntimeScorer::bytesFlat([self::attempt('N', 10, 5)]), 'one point proves nothing');
        $this->assertFalse(RuntimeScorer::bytesFlat([self::attempt('N', 10, 5), self::attempt('N', 100, 5, 'client_error', 404)]), 'a point that did not answer normally does not count');
        $this->assertTrue(RuntimeScorer::bytesFlat([
            self::attempt('N', 10, 5), self::attempt('N', 10, 99, 'ok', 200, 2), self::attempt('N', 100, 5),
        ]), 'a later attempt at the same scale is a warm-up repeat, not a second point');
    }

    public function testStopReasonsAreTestedInTheOrderOfTheirMeaning(): void
    {
        $attempts = [self::attempt('N', 10, 48), self::attempt('N', 1000, 48)];

        $this->assertSame('auth', RuntimeScorer::stopReason(['class' => 'unauthenticated'], []));
        $this->assertSame('passport_key', RuntimeScorer::stopReason(['class' => 'exception', 'reason_detail' => 'Invalid key supplied'], []));
        $this->assertSame('argument', RuntimeScorer::stopReason(['class' => 'unfilled_param'], []));
        $this->assertStringStartsWith('exception: ', RuntimeScorer::stopReason(['class' => 'exception', 'reason_detail' => 'boom'], []));
        $this->assertSame('client_error 400', RuntimeScorer::stopReason(['class' => 'client_error'], [self::attempt('N', 10, 3, 'client_error', 400)]));
        $this->assertSame('not_found', RuntimeScorer::stopReason(['class' => 'not_found'], []));
        $this->assertSame('reached', RuntimeScorer::stopReason(['class' => 'ok'], $attempts));
        $this->assertSame('mfa_prompt', RuntimeScorer::stopReason(['class' => 'ok'], $attempts, 48), 'only when the caller names that size');
        $this->assertSame('reached', RuntimeScorer::stopReason(['class' => 'ok'], $attempts, 49));
    }

    public function testCodeGroupFollowsConfidenceAndGrowth(): void
    {
        $this->assertSame('ON', RuntimeScorer::codeGroup(['growth' => 'O(N)', 'confidence' => 'medium']));
        $this->assertSame('ON', RuntimeScorer::codeGroup(['growth' => 'O(N*M)', 'confidence' => 'high']));
        $this->assertSame('ON', RuntimeScorer::codeGroup(['growth' => 'O(N)', 'confidence' => 'medium', 'at_least' => true]));
        $this->assertSame('O1', RuntimeScorer::codeGroup(['growth' => 'O(1)', 'confidence' => 'high']));
        $this->assertSame('E', RuntimeScorer::codeGroup(['growth' => 'O(1)', 'confidence' => 'high', 'at_least' => true]));
        $this->assertSame('COND', RuntimeScorer::codeGroup(['growth' => 'conditional', 'confidence' => 'medium', 'growth_if_condition_degree' => 1]));
        $this->assertSame('E', RuntimeScorer::codeGroup(['growth' => 'O(N)', 'confidence' => 'low']));
        $this->assertSame('E', RuntimeScorer::codeGroup(['growth' => null, 'confidence' => 'high']));
        $this->assertSame('E', RuntimeScorer::codeGroup(['growth' => 'O(N)', 'confidence' => 'high', 'decided' => false]));
    }

    public function testRatioLeavesConditionalRoutesOutOfTheDenominator(): void
    {
        $truth = $this->truth([
            ['O(N)', 'high'], ['O(N)', 'high'], ['O(N)', 'high'], ['conditional', 'medium'], ['O(1)', 'high'],
        ]);
        $records = [
            ['route' => '/r0', 'method' => 'GET', 'class' => 'ok', 'verdict' => 'measured_ON'],
            ['route' => '/r1', 'method' => 'GET', 'class' => 'ok', 'verdict' => 'measured_O1'],
            ['route' => '/r2', 'method' => 'GET', 'class' => 'unauthenticated', 'verdict' => 'not_measured'],
            ['route' => '/r3', 'method' => 'GET', 'class' => 'ok', 'verdict' => 'measured_ON'],
        ];

        $result = RuntimeScorer::score($truth, 'a', $records, []);

        $s = $result['summary'];
        $this->assertSame(['A' => 1, 'B' => 1, 'C' => 1, 'D' => 1, 'D_candidate' => 0, 'E' => 0, 'not_run' => 1], $s['counts']);
        $this->assertSame(3, $s['denominator']);
        $this->assertEqualsWithDelta(1 / 3, $s['a_over_abc'], 1.0E-9);
        $this->assertSame(['a GET /r1'], $s['c_routes']);
        $this->assertSame(['auth' => 1], $s['b_reasons']);
        $this->assertSame(3, $s['reached']);
    }

    public function testNoDenominatorGivesNoRatioInsteadOfDividingByZero(): void
    {
        $truth = $this->truth([['O(1)', 'high']]);

        $s = RuntimeScorer::score($truth, 'a', [['route' => '/r0', 'method' => 'GET', 'class' => 'ok', 'verdict' => 'measured_O1']], [])['summary'];

        $this->assertNull($s['a_over_abc']);
        $this->assertSame(0, $s['denominator']);
    }

    public function testAnswersWithoutAnAxisForQueriesAreLeftOut(): void
    {
        $truth = Truth::parse((string) json_encode([
            'schema' => Truth::SCHEMA, 'apps' => ['a' => ['commit' => 'x']],
            'routes' => [['id' => 'a GET /m', 'app' => 'a', 'method' => 'GET', 'uri' => '/m', 'axes' => ['memory' => ['growth' => 'O(N)', 'confidence' => 'high']]]],
        ]));

        $this->assertSame([], RuntimeScorer::score($truth, 'a', [], [])['rows']);
    }

    public function testTheLoaderRefusesAnswersThatCannotBeRead(): void
    {
        $good = ['schema' => Truth::SCHEMA, 'apps' => ['a' => []], 'routes' => []];
        $route = ['id' => 'a GET /x', 'app' => 'a', 'method' => 'GET', 'uri' => '/x', 'axes' => ['query' => ['growth' => 'O(N)']]];

        $this->assertSame([], Truth::parse((string) json_encode($good))['routes']);
        $this->assertRefused('{', 'not valid JSON');
        $this->assertRefused((string) json_encode(['schema' => 'other'] + $good), 'wrong shape');
        $this->assertRefused((string) json_encode(['routes' => [$route, $route]] + $good), 'twice');
        $this->assertRefused((string) json_encode(['routes' => [['app' => 'z'] + $route]] + $good), 'not listed');
        $this->assertRefused((string) json_encode(['routes' => [['axes' => ['query' => ['growth' => 'O(N^2)']]] + $route]] + $good), "unknown growth 'O(N^2)'");
        $this->assertRefused((string) json_encode(['routes' => [['axes' => ['cpu' => ['growth' => 'O(N)']]] + $route]] + $good), 'unknown axis');
        $this->assertRefused((string) json_encode(['routes' => [['axes' => ['query' => ['growth' => 'conditional']]] + $route]] + $good), 'growth_if_condition_degree');
        $this->assertRefused((string) json_encode(['routes' => [['id' => 'a GET /x'] + array_diff_key($route, ['axes' => 1])]] + $good), "lacks 'axes'");
    }

    public function testDegreesAreFixedByTheGrowthWord(): void
    {
        $this->assertSame(0, Truth::degree('O(1)'));
        $this->assertSame(1, Truth::degree('O(N)'));
        $this->assertSame(2, Truth::degree('O(N*M)'));
        $this->assertNull(Truth::degree('unknown'));
        $this->assertNull(Truth::degree('conditional'));
        $this->assertNull(Truth::degree(null));
    }

    private function assertRefused(string $json, string $message): void
    {
        try {
            Truth::parse($json);
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());

            return;
        }
        $this->fail('the loader accepted: ' . $json);
    }

    /**
     * @param list<array{string, string}> $growths  growth and confidence of route /r0, /r1, ...
     *
     * @return array<string, mixed>
     */
    private function truth(array $growths): array
    {
        $routes = [];
        foreach ($growths as $i => [$growth, $confidence]) {
            $axis = ['growth' => $growth, 'confidence' => $confidence];
            if ($growth === 'conditional') {
                $axis['growth_if_condition_degree'] = 1;
            }
            $routes[] = ['id' => "a GET /r{$i}", 'app' => 'a', 'method' => 'GET', 'uri' => "/r{$i}", 'sets' => ['round1-target'], 'axes' => ['query' => $axis]];
        }

        return Truth::parse((string) json_encode(['schema' => Truth::SCHEMA, 'apps' => ['a' => ['commit' => 'x']], 'routes' => $routes]));
    }

    /** @return array<string, mixed> */
    private static function attempt(string $axis, int $scale, int $bytes, string $class = 'ok', int $status = 200, int $attempt = 1): array
    {
        return ['axis' => $axis, 'scale' => $scale, 'bytes' => $bytes, 'class' => $class, 'status' => $status, 'attempt' => $attempt];
    }
}
