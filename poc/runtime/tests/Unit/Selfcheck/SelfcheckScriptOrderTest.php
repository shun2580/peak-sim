<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Selfcheck;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * bin/selfcheck, run for real (bash and the PHP half) with stand-ins for the commands that need Docker. The stand-in of
 * `measure` is strict on its own: it refuses to run unless the order record says that the gate was opened after the seal, and it
 * writes down what it saw. So these tests fail if the measurement can start before the estimate is sealed, and they show it:
 * a copy of the script with the gate (or the seal) taken out is run too, and must not get as far as measuring.
 */
final class SelfcheckScriptOrderTest extends TestCase
{
    private string $dir = '';

    private string $tools = '';

    private string $app = '';

    private string $static = '';

    private string $out = '';

    private string $log = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/peak-selfscript-' . bin2hex(random_bytes(4));
        $this->tools = $this->dir . '/tools';
        $this->app = $this->dir . '/app';
        $this->out = $this->dir . '/out';
        $this->log = $this->dir . '/calls.log';
        mkdir($this->tools, 0777, true);
        mkdir($this->app . '/vendor', 0777, true);
        mkdir($this->out);
        file_put_contents($this->app . '/vendor/autoload.php', '<?php');
        $this->static = $this->dir . '/analysis.json';
        file_put_contents($this->static, '{}');
        file_put_contents($this->log, '');
        $this->writeStandIns();
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    private function stand(string $name, string $body): void
    {
        file_put_contents($this->tools . '/' . $name, "#!/usr/bin/env bash\n" . $body);
        chmod($this->tools . '/' . $name, 0755);
    }

    private function writeStandIns(): void
    {
        $rows = [];
        $attempts = [];
        for ($i = 1; $i <= 5; ++$i) {
            $rows[] = ['route' => 'GET /r' . $i, 'complete' => true, 'queries' => 10.0, 'rows_examined' => 100.0, 'marked' => '__M' . $i . '__', 'rows_examined_upper' => null,
                'peak_memory' => ['status' => 'predicted', 'value' => 5000.0]];
            $attempts[] = json_encode(['route' => '/r' . $i, 'method' => 'GET', 'scale' => 1000, 'axis' => 'N', 'attempt' => 2, 'class' => 'ok', 'queries' => 10, 'rows_examined' => 100, 'peak_mem' => 5000]);
        }
        $estimate = json_encode(['at' => 1000, 'app' => 'demo', 'routes' => $rows]);
        $marked5 = str_replace(['"__M5__"', '"__M1__"', '"__M2__"', '"__M3__"', '"__M4__"'], ['true', 'false', 'false', 'false', 'false'], (string) $estimate);
        $none = str_replace(['"__M5__"', '"__M1__"', '"__M2__"', '"__M3__"', '"__M4__"'], 'false', (string) $estimate);
        file_put_contents($this->tools . '/estimate_marked.json', $marked5);
        file_put_contents($this->tools . '/estimate_none.json', $none);
        file_put_contents($this->tools . '/attempts.jsonl', implode("\n", $attempts) . "\n");

        $arg = 'arg() { for a in "$@"; do case "$a" in --$ARGNAME=*) printf "%s" "${a#--$ARGNAME=}";; esac; done; }' . "\n";
        $log = 'log() { echo "$1" >> "$PEAK_TEST_LOG"; }' . "\n";
        $head = $arg . $log . 'ARGNAME=out; out="$(arg "$@")"; ARGNAME=label; label="$(arg "$@")"' . "\n";

        $this->stand('capture', $head . 'log capture; [ "${FAIL_STEP:-}" = capture ] && exit 3; mkdir -p "$out"; echo "{}" > "$out/capture.json"; echo "{}" > "$out/tasks.json"' . "\n");
        $this->stand('explain-at-scale', $head . 'log explain-at-scale; mkdir -p "$out/$label"; echo \'{"totals":{"statement_types":9,"plan_unstable":1,"plan_changes_at_scale":0,"explain_unavailable":0}}\' > "$out/$label/explain.json"' . "\n");
        $this->stand('fit', 'echo "fit" >> "$PEAK_TEST_LOG"; echo "{}"' . "\n");
        $this->stand('predict', 'echo "predict" >> "$PEAK_TEST_LOG"; echo "{}"' . "\n");
        $this->stand('estimate', 'echo "estimate" >> "$PEAK_TEST_LOG"; if [ -n "${NO_MARKED:-}" ]; then cat "$(dirname "$0")/estimate_none.json"; else cat "$(dirname "$0")/estimate_marked.json"; fi' . "\n");
        $this->stand('marked-routes', 'echo "marked-routes" >> "$PEAK_TEST_LOG"; if [ -n "${NO_MARKED:-}" ]; then echo \'{"routes":[]}\'; else echo \'{"routes":[{"route":"GET /r5"}]}\'; fi' . "\n");
        // measure refuses unless the gate was opened after the seal; it writes down what it saw and the arguments it was given
        $this->stand('measure', $head
            . 'record="$(dirname "$out")"' . "\n"
            . 'if ! grep -q "^measure_started " "$record/estimate/order.log" 2>/dev/null || [ ! -f "$record/estimate/seal.json" ]; then log MEASURE_WITHOUT_GATE; exit 99; fi' . "\n"
            . 'log "measure $*"; mkdir -p "$out"; cp "$(dirname "$0")/attempts.jsonl" "$out/attempts.jsonl"; echo "{}" > "$out/tasks.json"' . "\n");
        $this->stand('variance-run', $head
            . 'log "variance-run $*"; [ -f "$(dirname "$out")/measure/tasks.json" ] || exit 98' . "\n"
            . 'mkdir -p "$out/$label/db0/g1/plain_lap1/01_r5"; echo \'{"route":"/r5","method":"GET","scale":1000,"axis":"N","attempt":2,"class":"ok","queries":10,"rows_examined":100,"peak_mem":5000}\' > "$out/$label/db0/g1/plain_lap1/01_r5/attempt.jsonl"' . "\n");
    }

    private static function script(): string
    {
        return dirname(__DIR__, 3) . '/bin/selfcheck';
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $env
     *
     * @return array{int, string}
     */
    private function exec(string $script, array $args, array $env = []): array
    {
        $cmd = array_merge(['bash', $script], $args);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), ['PEAK_SELFCHECK_TOOLS' => $this->tools, 'PEAK_TEST_LOG' => $this->log], $env));
        self::assertIsResource($proc);
        $text = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $text];
    }

    /**
     * @param array<string, string> $override option name => value, replacing the default of that option
     *
     * @return list<string>
     */
    private function args(string $label = 'run1', array $override = []): array
    {
        $opts = array_merge(['app' => $this->app, 'static' => $this->static, 'scale' => '20000', 'out' => $this->out, 'label' => $label, 'now' => '2026-11-09T17:00:00+09:00'], $override);

        return array_map(static fn (string $k, string $v): string => '--' . $k . '=' . $v, array_keys($opts), array_values($opts));
    }

    /** @return list<string> the first word of every call the stand-ins logged, in order */
    private function calls(): array
    {
        return array_map(static fn (string $l): string => explode(' ', $l)[0], array_values(array_filter(explode("\n", (string) file_get_contents($this->log)))));
    }

    /** @return array<string, mixed> */
    private function savedResult(string $label = 'run1'): array
    {
        return json_decode((string) file_get_contents($this->out . '/' . $label . '/selfcheck-result.json'), true);
    }

    public function testTheStepsRunInTheOrderOfTheSpecAndTheMeasurementStartsOnlyAfterTheSealAndTheGate(): void
    {
        [$code, $text] = $this->exec(self::script(), $this->args());

        self::assertSame(0, $code, $text);
        self::assertSame(['capture', 'explain-at-scale', 'fit', 'predict', 'estimate', 'marked-routes', 'measure', 'variance-run'], $this->calls());
        $lines = explode("\n", trim((string) file_get_contents($this->out . '/run1/estimate/order.log')));
        self::assertCount(2, $lines);
        self::assertStringStartsWith('estimate_sealed ', $lines[0]);
        self::assertStringStartsWith('measure_started ', $lines[1]);
        // the hash the order record keeps is that of the estimate file as it is on disk
        self::assertStringContainsString('estimate.json=' . hash_file('sha256', $this->out . '/run1/estimate/estimate.json'), $lines[0]);
        self::assertStringContainsString('estimate.json=' . hash_file('sha256', $this->out . '/run1/estimate/estimate.json'), $lines[1]);
        $steps = (string) file_get_contents($this->out . '/run1/steps.log');
        self::assertLessThan(strpos($steps, 'begin gate'), strpos($steps, 'end seal exit=0'));
        self::assertLessThan(strpos($steps, 'begin measure'), strpos($steps, 'end gate exit=0'));
    }

    public function testTheResultIsSavedWithTheOrderAndTheHashOfTheSealedEstimate(): void
    {
        [$code, $text] = $this->exec(self::script(), $this->args());

        self::assertSame(0, $code, $text);
        $r = $this->savedResult();
        self::assertSame('selfcheck-result', $r['kind']);
        self::assertSame('pass', $r['summary']['verdict']);
        self::assertSame(1000, $r['target_scale']);   // the size the estimate (the stand-in's) says, written by the judge
        self::assertSame(hash_file('sha256', $this->out . '/run1/estimate/estimate.json'), $r['order']['sealed_sha256']['estimate.json']);
        self::assertSame('2026-11-09T17:00:00+09:00', $r['checked_at']);
        self::assertSame(1, $r['summary']['plan_unstable_statements']);
        self::assertStringContainsString('result: ' . $this->out . '/run1/selfcheck-result.json', $text);
    }

    public function testTheMarkedRoutesAreMeasuredColdThreeMoreTimesAndTheEstimateIsMeasuredAtTheTargetSize(): void
    {
        [$code, $text] = $this->exec(self::script(), $this->args());

        self::assertSame(0, $code, $text);
        $calls = explode("\n", trim((string) file_get_contents($this->log)));
        $measure = array_values(array_filter($calls, static fn (string $l): bool => str_starts_with($l, 'measure ')))[0];
        $extra = array_values(array_filter($calls, static fn (string $l): bool => str_starts_with($l, 'variance-run ')))[0];
        self::assertStringContainsString('--scales=20000', $measure);
        self::assertStringContainsString('--warm=off', $measure);
        self::assertStringContainsString('--request-timeout=600', $measure);
        self::assertStringContainsString('--routes=GET /r5', $extra);
        self::assertStringContainsString('--scale=20000', $extra);
        self::assertStringContainsString('--dbs=1 --laps=3', $extra);
        self::assertStringContainsString('--profile=none', $extra);
        $row = array_values(array_filter($this->savedResult()['routes'], static fn (array $x): bool => $x['route'] === 'GET /r5'))[0];
        self::assertSame(2, $row['cold_values_used']);
    }

    public function testWithNoMarkedRouteThereIsNoExtraColdStep(): void
    {
        [$code, $text] = $this->exec(self::script(), $this->args(), ['NO_MARKED' => '1']);

        self::assertSame(0, $code, $text);
        self::assertSame(['capture', 'explain-at-scale', 'fit', 'predict', 'estimate', 'marked-routes', 'measure'], $this->calls());
        self::assertStringContainsString('skip extra-cold', (string) file_get_contents($this->out . '/run1/steps.log'));
    }

    public function testARouteListAndAProfileAreHandedOnToTheCommandsThatTakeThem(): void
    {
        [$code, $text] = $this->exec(self::script(), array_merge($this->args(), ['--routes=GET /r1,GET /r5', '--profile=demo']));

        self::assertSame(0, $code, $text);
        $calls = explode("\n", trim((string) file_get_contents($this->log)));
        $measure = array_values(array_filter($calls, static fn (string $l): bool => str_starts_with($l, 'measure ')))[0];
        $extra = array_values(array_filter($calls, static fn (string $l): bool => str_starts_with($l, 'variance-run ')))[0];
        self::assertStringContainsString('--only=GET /r1,GET /r5', $measure);
        self::assertStringContainsString('--profile=demo', $measure);
        self::assertStringContainsString('--profile=demo', $extra);
        self::assertStringNotContainsString('--profile=none', $extra);
    }

    public function testTakingTheGateOutOfTheScriptMakesTheMeasurementRefuseToRun(): void
    {
        $original = (string) file_get_contents(self::script());
        $mutated = $this->mutated($original, 'if ! sc gate --dir=estimate; then', 'if ! true; then');
        [$code, $text] = $this->exec($mutated, $this->args());

        // the stand-in of `measure` sees no measure_started line and stops; the run ends with a failed step, no result is judged
        self::assertSame(15, $code, $text);
        self::assertContains('MEASURE_WITHOUT_GATE', $this->calls());
        self::assertFileDoesNotExist($this->out . '/run1/selfcheck-result.json');
    }

    public function testTakingTheSealOutOfTheScriptMakesTheGateRefuseAndNothingIsMeasured(): void
    {
        $original = (string) file_get_contents(self::script());
        $mutated = $this->mutated($original, 'step seal sc seal --dir=estimate --files=estimate.json,marked-routes.json', 'true');
        [$code, $text] = $this->exec($mutated, $this->args());

        self::assertSame(20, $code, $text);
        self::assertNotContains('measure', $this->calls());
        self::assertNotContains('MEASURE_WITHOUT_GATE', $this->calls());
        self::assertStringContainsString('nothing was measured', $text);
    }

    public function testASealedFileChangedBeforeTheGateStopsTheRunWithExit20(): void
    {
        // the real seal is taken, then a line is added to estimate.json just before the gate (a copy of the script does that):
        // the gate sees a hash that is no longer the sealed one and refuses, so `measure` never starts
        $original = (string) file_get_contents(self::script());
        $mutated = $this->mutated($original, 'if ! sc gate --dir=estimate; then', 'echo tampered >> "$run/estimate/estimate.json"; if ! sc gate --dir=estimate; then');
        [$code, $text] = $this->exec($mutated, $this->args());

        self::assertSame(20, $code, $text);
        self::assertNotContains('measure', $this->calls());
        self::assertFileDoesNotExist($this->out . '/run1/selfcheck-result.json');
    }

    public function testAStepThatFailsStopsTheRunWithExit15AndNothingAfterItRuns(): void
    {
        [$code, $text] = $this->exec(self::script(), $this->args(), ['FAIL_STEP' => 'capture']);

        self::assertSame(15, $code, $text);
        self::assertSame(['capture'], $this->calls());
        self::assertStringContainsString('end capture exit=3', (string) file_get_contents($this->out . '/run1/steps.log'));
    }

    public function testARecordIsNeverWrittenOver(): void
    {
        self::assertSame(0, $this->exec(self::script(), $this->args())[0]);
        [$code, $text] = $this->exec(self::script(), $this->args());

        self::assertSame(2, $code);
        self::assertStringContainsString('already exists', $text);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function wrongOptions(): iterable
    {
        yield 'relative out' => [['out' => 'rel'], '--out must be an absolute path'];
        yield 'relative app' => [['app' => 'rel'], '--app must be an absolute path'];
        yield 'small scale not below the scale' => [['small-scale' => '20000'], '--small-scale (20000) must be smaller than --scale (20000)'];
        yield 'capture sweep not ending at the small scale' => [['small-scale' => '5000'], 'the last of --capture-scales (10000) must equal --small-scale (5000)'];
        yield 'too few capture scales' => [['capture-scales' => '100,1000'], '--capture-scales takes three or more sizes'];
        yield 'scale not a number' => [['scale' => 'abc'], '--scale must be a positive integer'];
        yield 'bad project name' => [['project' => 'Bad_Name'], '--project is lowercase'];
        yield 'timeout too long' => [['request-timeout' => '601'], '--request-timeout is 1 to 600'];
        yield 'bad label' => [['label' => 'a/b'], '--label is letters'];
        yield 'bad data' => [['data' => 'csv'], '--data takes generator or seeder'];
    }

    /** @param array<string, string> $override */
    #[DataProvider('wrongOptions')]
    public function testAWrongOptionIsExit2WithTheUsageTextAndNothingRuns(array $override, string $message): void
    {
        [$code, $text] = $this->exec(self::script(), $this->args('wrong', $override));

        self::assertSame(2, $code, $text);
        self::assertStringContainsString($message, $text);
        self::assertStringContainsString('usage: bin/selfcheck', $text);
        self::assertSame([], $this->calls());
    }

    public function testNoArgumentsAndAnUnknownArgumentAreExit2(): void
    {
        [$code, $text] = $this->exec(self::script(), []);
        self::assertSame(2, $code);
        self::assertStringContainsString('--app is required', $text);

        [$code, $text] = $this->exec(self::script(), ['--bogus']);
        self::assertSame(2, $code);
        self::assertStringContainsString('unknown argument: --bogus', $text);
        self::assertSame([], $this->calls());
    }

    /** A copy of the script with one line changed (the copy points at the real bin/ directory, so it finds the PHP half). */
    private function mutated(string $original, string $from, string $to): string
    {
        self::assertStringContainsString($from, $original, 'the line to take out is not in the script any more; update this test');
        $here = 'here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"';
        self::assertStringContainsString($here, $original);
        $copy = str_replace([$here, $from], ['here="' . dirname(self::script()) . '"', $to], $original);
        self::assertNotSame($original, $copy);
        $path = $this->dir . '/selfcheck-mutated-' . bin2hex(random_bytes(3));
        file_put_contents($path, $copy);

        return $path;
    }
}
