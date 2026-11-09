<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Selfcheck;

use PeakSim\Runtime\Selfcheck\SelfcheckCommand;
use PHPUnit\Framework\TestCase;

/** The PHP half of bin/selfcheck: seal, gate, marked, judge. The judge refuses a record that did not keep the order. */
final class SelfcheckCommandTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/peak-selfcmd-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/estimate', 0777, true);
        mkdir($this->dir . '/measure');
        $routes = [];
        $attempts = [];
        for ($i = 1; $i <= 5; ++$i) {
            $routes[] = [
                'route' => 'GET /r' . $i, 'complete' => true, 'queries' => 10.0, 'rows_examined' => 100.0, 'marked' => $i === 5,
                'rows_examined_upper' => $i === 5 ? 200.0 : null, 'peak_memory' => ['status' => 'predicted', 'value' => 5000.0],
            ];
            $attempts[] = json_encode(['route' => '/r' . $i, 'method' => 'GET', 'scale' => 1000, 'axis' => 'N', 'attempt' => 2, 'class' => 'ok', 'queries' => 10, 'rows_examined' => 100, 'peak_mem' => 5000]);
        }
        file_put_contents($this->dir . '/estimate/estimate.json', json_encode(['at' => 1000, 'app' => 'demo', 'routes' => $routes]));
        file_put_contents($this->dir . '/estimate/marked-routes.json', json_encode(['routes' => [['route' => 'GET /r5']]]));
        file_put_contents($this->dir . '/measure/attempts.jsonl', implode("\n", $attempts) . "\n");
        file_put_contents($this->dir . '/explain.json', json_encode(['totals' => ['statement_types' => 9, 'plan_unstable' => 1, 'plan_changes_at_scale' => 0, 'explain_unavailable' => 2]]));
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private static function invoke(array $args): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $code = (new SelfcheckCommand($out, $err))->run($args);
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    /** @return list<string> */
    private function judgeArgs(): array
    {
        return ['judge', '--sealed=' . $this->dir . '/estimate', '--measured=' . $this->dir . '/measure', '--explain=' . $this->dir . '/explain.json',
            '--out=' . $this->dir . '/result.json', '--now=2026-11-09T16:30:00+09:00'];
    }

    private function sealAndGate(): void
    {
        self::assertSame(0, self::invoke(['seal', '--dir=' . $this->dir . '/estimate', '--files=estimate.json,marked-routes.json', '--now=2026-11-09T16:00:00+09:00'])[0]);
        self::assertSame(0, self::invoke(['gate', '--dir=' . $this->dir . '/estimate', '--now=2026-11-09T16:00:05+09:00'])[0]);
    }

    public function testWrongUsageIsExit2(): void
    {
        foreach ([[], ['nope'], ['seal'], ['seal', '--dir=x'], ['gate'], ['judge', '--sealed=x'], ['marked'], ['gate', '--dir=x', '--bogus=1'], ['gate', '--dir=x', '--now=not a time']] as $args) {
            [$code, , $err] = self::invoke($args);
            self::assertSame(2, $code, implode(' ', $args));
            self::assertStringContainsString('usage:', $err);
        }
    }

    public function testTheGateRefusesWithExit20WhenNothingIsSealed(): void
    {
        [$code, , $err] = self::invoke(['gate', '--dir=' . $this->dir . '/estimate']);

        self::assertSame(20, $code);
        self::assertStringContainsString('refused, the order is not kept', $err);
        self::assertFileDoesNotExist($this->dir . '/estimate/order.log');
    }

    public function testTheJudgeRefusesWithExit20AndWritesNothingWhenTheMeasurementDidNotGoThroughTheGate(): void
    {
        self::invoke(['seal', '--dir=' . $this->dir . '/estimate', '--files=estimate.json,marked-routes.json']);
        [$code] = self::invoke($this->judgeArgs());

        self::assertSame(20, $code);
        self::assertFileDoesNotExist($this->dir . '/result.json');
    }

    public function testTheJudgeRefusesAnEstimateChangedAfterTheGate(): void
    {
        $this->sealAndGate();
        file_put_contents($this->dir . '/estimate/estimate.json', json_encode(['at' => 1000, 'app' => 'demo', 'routes' => []]));
        [$code, , $err] = self::invoke($this->judgeArgs());

        self::assertSame(20, $code);
        self::assertStringContainsString('changed after it was sealed', $err);
        self::assertFileDoesNotExist($this->dir . '/result.json');
    }

    public function testTheJudgeWritesTheResultAndPrintsTheTable(): void
    {
        $this->sealAndGate();
        [$code, $out] = self::invoke($this->judgeArgs());

        self::assertSame(0, $code);
        $result = json_decode((string) file_get_contents($this->dir . '/result.json'), true);
        self::assertSame('selfcheck-result', $result['kind']);
        self::assertSame('0.1', $result['schema_version']);
        self::assertSame('demo', $result['app']);
        self::assertSame(1000, $result['target_scale']);
        self::assertSame('2026-11-09T16:30:00+09:00', $result['checked_at']);
        self::assertSame('2026-11-09T16:00:00+09:00', $result['order']['estimate_sealed_at']);
        self::assertSame('2026-11-09T16:00:05+09:00', $result['order']['measure_started_at']);
        self::assertSame(hash_file('sha256', $this->dir . '/estimate/estimate.json'), $result['order']['sealed_sha256']['estimate.json']);
        self::assertSame('pass', $result['summary']['verdict']);
        self::assertSame(1, $result['summary']['plan_unstable_statements']);
        self::assertSame(2, $result['summary']['explain_unavailable_statements']);
        self::assertStringContainsString('PASS', $out);
        self::assertStringContainsString('plan_unstable statements: 1', $out);
    }

    public function testTheExtraColdTriesOfTheMarkedRoutesAreReadFromEveryAttemptFileUnderTheDirectory(): void
    {
        mkdir($this->dir . '/extra/db0/g1/plain_lap1/01_r5', 0777, true);
        mkdir($this->dir . '/extra/db0/g1/plain_lap2/01_r5', 0777, true);
        $rec = static fn (int $rows): string => json_encode(['route' => '/r5', 'method' => 'GET', 'scale' => 1000, 'axis' => 'N', 'attempt' => 2, 'class' => 'ok', 'queries' => 10, 'rows_examined' => $rows, 'peak_mem' => 5000]) . "\n";
        file_put_contents($this->dir . '/extra/db0/g1/plain_lap1/01_r5/attempt.jsonl', $rec(150));
        // a record with a body in front of the JSON on the same line (a route that answers with a file)
        file_put_contents($this->dir . '/extra/db0/g1/plain_lap2/01_r5/attempt.jsonl', 'PK-binary-body' . $rec(190));
        $this->sealAndGate();
        $args = array_merge($this->judgeArgs(), ['--extra=' . $this->dir . '/extra']);
        [$code] = self::invoke($args);

        self::assertSame(0, $code);
        $result = json_decode((string) file_get_contents($this->dir . '/result.json'), true);
        $r5 = array_values(array_filter($result['routes'], static fn (array $r): bool => $r['route'] === 'GET /r5'))[0];
        self::assertSame(3, $r5['cold_values_used']);
        // rule B: the largest of 100 (measure), 150 and 190 is 190; the estimate 100 against it is 90/190 = 47.4%
        self::assertSame(190.0, $r5['q']['rows_examined']['B']['measured']);
        self::assertFalse($r5['q']['rows_examined']['B']['within_min']);
        self::assertSame(100.0, $r5['q']['rows_examined']['A']['measured']);
    }

    public function testMarkedPrintsTheMarkedRoutesCommaSeparatedOnlyForAnIntactSeal(): void
    {
        self::invoke(['seal', '--dir=' . $this->dir . '/estimate', '--files=estimate.json,marked-routes.json']);
        [$code, $out] = self::invoke(['marked', '--sealed=' . $this->dir . '/estimate']);
        self::assertSame(0, $code);
        self::assertSame('GET /r5', $out);

        file_put_contents($this->dir . '/estimate/marked-routes.json', json_encode(['routes' => []]));
        [$code, $out] = self::invoke(['marked', '--sealed=' . $this->dir . '/estimate']);
        self::assertSame(20, $code);
        self::assertSame('', $out);
    }

    public function testAnUnreadableMeasurementIsExit3(): void
    {
        $this->sealAndGate();
        unlink($this->dir . '/measure/attempts.jsonl');
        [$code, , $err] = self::invoke($this->judgeArgs());

        self::assertSame(3, $code);
        self::assertStringContainsString('cannot read the inputs', $err);
    }
}
