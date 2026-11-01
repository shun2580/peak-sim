<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Measure;

use PeakSim\Runtime\Measure\MeasureRequest;
use PeakSim\Runtime\Measure\ProcessOneRunner;
use PeakSim\Runtime\Run\Attempt;
use PHPUnit\Framework\TestCase;

final class ProcessOneRunnerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/peak-runner-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function script(string $body): string
    {
        $path = $this->dir . '/stub.php';
        file_put_contents($path, "<?php\n" . $body);

        return $path;
    }

    private static function request(): MeasureRequest
    {
        return new MeasureRequest('ord', 100, 'N', '/ok/eager', '/ok/eager', 'GET', null, null, false, false, [], false, [Attempt::WARMUP, Attempt::COLD]);
    }

    public function test_it_reads_the_attempts_the_child_prints_and_its_exit_code(): void
    {
        $line = (new Attempt('/ok/eager', 'GET', 100, 'N', Attempt::COLD, 200, 3, ['mysql' => 3], ['orders'], 10, 1000, false, 'ok', null))->toJson();
        $path = $this->script('echo ' . var_export($line, true) . ' . "\n"; echo "noise\n"; fwrite(STDERR, "booted\n"); exit(14);');

        $result = (new ProcessOneRunner($path, ['PATH' => '/usr/bin:/bin']))->run(self::request());

        $this->assertSame(14, $result->exit);
        $this->assertCount(1, $result->attempts);
        $this->assertSame(3, $result->attempts[0]->queries);
        $this->assertSame("booted\n", $result->stderr);
    }

    public function test_it_passes_the_arguments_and_the_tag_to_the_child(): void
    {
        $path = $this->script('fwrite(STDERR, getenv("PEAK_MEASURE_DB_TAG") . " " . implode(" ", array_slice($argv, 1)));');

        $result = (new ProcessOneRunner($path, ['PATH' => '/usr/bin:/bin']))->run(self::request());

        $this->assertStringStartsWith('ord --scale=100 --uri=/ok/eager --method=GET', $result->stderr);
        $this->assertStringContainsString('--phases=warmup,cold', $result->stderr);
    }

    public function test_a_child_that_runs_out_of_time_is_stopped_and_reported(): void
    {
        $path = $this->script('echo "started\n"; sleep(30);');

        $started = microtime(true);
        $result = (new ProcessOneRunner($path, ['PATH' => '/usr/bin:/bin'], 1))->run(self::request());

        $this->assertSame(124, $result->exit);
        $this->assertLessThan(10, microtime(true) - $started);
        $this->assertStringContainsString('timed out', $result->stderr);
    }

    public function test_lots_of_output_does_not_block_the_child(): void
    {
        $path = $this->script('for ($i = 0; $i < 3000; $i++) { fwrite(STDERR, str_repeat("x", 100) . "\n"); } echo "{}\n";');

        $result = (new ProcessOneRunner($path, ['PATH' => '/usr/bin:/bin']))->run(self::request());

        $this->assertSame(0, $result->exit);
        $this->assertGreaterThan(300000, strlen($result->stderr));
    }

    public function test_the_request_round_trips_through_the_measure_one_argument_parser(): void
    {
        $request = new MeasureRequest('ord', 1000, 'M', '/orders/{order}', '/orders/7', 'POST', 'App\\Models\\User:1:web', ['name' => 'x'], false, true, ['X-A' => '1'], true, [Attempt::COLD, Attempt::WARM]);

        $parsed = \PeakSim\Runtime\Run\MeasureOne::parseArguments($request->toArguments());

        $this->assertSame(1000, $parsed['scale']);
        $this->assertSame('/orders/7', $parsed['uri']);
        $this->assertSame('POST', $parsed['method']);
        $this->assertSame('/orders/{order}', $parsed['route']);
        $this->assertSame('M', $parsed['axis']);
        $this->assertSame(['X-A' => '1'], $parsed['headers']);
        $this->assertSame(['name' => 'x'], $parsed['body']);
        $this->assertTrue($parsed['csrf']);
        $this->assertSame('App\\Models\\User:1:web', $parsed['actAs']);
        $this->assertTrue($parsed['gateBypass']);
        $this->assertSame([Attempt::COLD, Attempt::WARM], $parsed['phases']);
    }

    public function test_an_empty_body_is_sent_as_an_empty_object(): void
    {
        $request = new MeasureRequest('ord', 1, 'N', '/a', '/a', 'POST', null, [], false, false, [], false, [Attempt::COLD]);

        $parsed = \PeakSim\Runtime\Run\MeasureOne::parseArguments($request->toArguments());

        $this->assertSame([], $parsed['body']);
        $this->assertContains('--body-json={}', $request->toArguments());
    }
}
