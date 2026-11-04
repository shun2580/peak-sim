<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Judge;

use PeakSim\Runtime\Judge\CompareExtrapolationCommand;
use PeakSim\Runtime\Judge\PredictCommand;
use PHPUnit\Framework\TestCase;

/** The two commands, run on files in a temporary directory. */
final class ExtrapolationCommandsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/peak-extrapolation-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/run/*') ?: [] as $f) {
            unlink($f);
        }
        if (is_dir($this->dir . '/run')) {
            rmdir($this->dir . '/run');
        }
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private static function exec(object $command, array $args): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $class = $command::class;
        $code = (new $class($out, $err))->run($args);
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    public function test_predict_then_compare_gives_a_verdict_from_files(): void
    {
        $routes = [];
        $lines = '';
        for ($i = 0; $i < 5; ++$i) {
            $routes[] = ['route' => "GET /r{$i}", 'axis' => 'N', 'table' => 't', 'static_lower_bound' => 'O(N)', 'quantities' => [
                // 2 + 1 * 100^1 = 102 queries; 0 + 10 * 100^1 = 1000 rows
                'queries' => ['fit_status' => 'ok', 'a' => 2.0, 'b' => 1.0, 'k' => 1.0],
                'rows_examined' => ['fit_status' => 'ok', 'a' => 0.0, 'b' => 10.0, 'k' => 1.0],
                'peak_memory' => ['fit_status' => 'insufficient_scales', 'reason' => 'scale 10000: timeout'],
            ]];
            $lines .= json_encode(['route' => "/r{$i}", 'method' => 'GET', 'scale' => 100, 'axis' => 'N', 'attempt' => 2, 'class' => 'ok', 'queries' => 102, 'rows_examined' => 1000, 'peak_mem' => 1]) . "\n";
        }
        file_put_contents($this->dir . '/fits.json', json_encode(['schema_version' => '0.1', 'source' => 'run-1', 'routes' => $routes]));
        mkdir($this->dir . '/run');
        file_put_contents($this->dir . '/run/attempts.jsonl', $lines);

        [$code, $predictions, $err] = self::exec(new PredictCommand(STDOUT, STDERR), ['--fits=' . $this->dir . '/fits.json', '--at=100']);
        $this->assertSame(0, $code, $err);
        file_put_contents($this->dir . '/predictions.json', $predictions);

        [$code, $out, $err] = self::exec(new CompareExtrapolationCommand(STDOUT, STDERR), ['--predictions=' . $this->dir . '/predictions.json', '--measured=' . $this->dir . '/run']);
        $this->assertSame(0, $code, $err);
        $result = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pass', $result['verdict']);
        $this->assertSame('run', $result['measured_source']);
        $this->assertSame(5, $result['summary']['denominator']);

        [, $again] = self::exec(new CompareExtrapolationCommand(STDOUT, STDERR), ['--predictions=' . $this->dir . '/predictions.json', '--measured=' . $this->dir . '/run/attempts.jsonl']);
        $this->assertSame(json_decode($again, true)['routes'], $result['routes']);

        [$code, $md] = self::exec(new CompareExtrapolationCommand(STDOUT, STDERR), ['--predictions=' . $this->dir . '/predictions.json', '--measured=' . $this->dir . '/run', '--format=markdown']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Verdict: **pass**', $md);
    }

    public function test_wrong_usage_exits_with_two_and_says_why(): void
    {
        [$code, , $err] = self::exec(new PredictCommand(STDOUT, STDERR), ['--at=100']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('--fits is required', $err);

        [$code, , $err] = self::exec(new PredictCommand(STDOUT, STDERR), ['--fits=x', '--at=0']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('--at must be a positive integer', $err);

        [$code, , $err] = self::exec(new CompareExtrapolationCommand(STDOUT, STDERR), ['--predictions=' . $this->dir . '/none.json', '--measured=' . $this->dir]);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('--predictions is not a readable file', $err);
    }
}
