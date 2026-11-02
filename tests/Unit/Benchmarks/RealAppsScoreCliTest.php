<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Benchmarks;

use PHPUnit\Framework\TestCase;

/**
 * The command line of the scoring tool, end to end, on small files written by the test itself.
 */
final class RealAppsScoreCliTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testStaticScoringPrintsTheTableAndTheRowsThatAreNotAgreements(): void
    {
        [$code, $out] = $this->score(['--truth=' . $this->truthFile(), '--analysis=a=' . $this->analysisFile(), '--rows']);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('answers: 2 routes', $out);
        $this->assertMatchesRegularExpression('/^all\s+2\s+2\s+1\s+0\s+0\s+0\s+1\s+0$/m', $out);
        $this->assertStringContainsString('missed', $out);
        $this->assertStringContainsString('a GET /light', $out);
    }

    public function testJsonOutputCarriesTheSummaryAndTheRows(): void
    {
        [$code, $out] = $this->score(['--truth=' . $this->truthFile(), '--analysis=a=' . $this->analysisFile(), '--format=json']);

        $this->assertSame(0, $code, $out);
        $report = json_decode($out, true);
        $this->assertSame(1, $report['static']['summary']['all']['missed']);
        $this->assertSame(['match', 'missed'], array_column($report['static']['rows'], 'category'));
    }

    public function testRuntimeScoringReadsTheTwoJsonlFiles(): void
    {
        $dir = sys_get_temp_dir() . '/peak-sim-bench-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $routes = $dir . '/routes.jsonl';
        $attempts = $dir . '/attempts.jsonl';
        file_put_contents($routes, json_encode(['route' => '/heavy', 'method' => 'GET', 'class' => 'ok', 'verdict' => 'measured_ON']) . "\n");
        file_put_contents($attempts, json_encode(['route' => '/heavy', 'method' => 'GET', 'axis' => 'N', 'scale' => 10, 'attempt' => 1, 'status' => 200, 'bytes' => 9, 'class' => 'ok']) . "\n");

        [$code, $out] = $this->score(['--truth=' . $this->truthFile(), '--runtime=a:R1=' . $dir]);

        unlink($routes);
        unlink($attempts);
        rmdir($dir);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('== runtime: a:R1 ==', $out);
        $this->assertStringContainsString('A 1', $out);
        $this->assertStringContainsString('D = 0: confirmed 0 / doubtful 0', $out);
        $this->assertStringContainsString('A / (A+B+C) = 1/1 = 100.0%', $out);
    }

    public function testUsageErrorsAndUnreadableInputsHaveTheirOwnExitStatus(): void
    {
        $this->assertSame(2, $this->score([])[0], 'nothing to score');
        $this->assertSame(2, $this->score(['--bogus'])[0]);
        $this->assertSame(2, $this->score(['--analysis=noequalsign'])[0]);
        $this->assertSame(2, $this->score(['--runtime=a=dir'])[0], 'the run needs a label');
        $this->assertSame(3, $this->score(['--truth=/nonexistent/truth.json', '--analysis=a=x'])[0]);
        $this->assertSame(3, $this->score(['--truth=' . $this->truthFile(), '--analysis=a=/nonexistent/analysis.json'])[0]);
        $this->assertSame(3, $this->score(['--truth=' . $this->truthFile(), '--clone=a=/nonexistent/clone', '--analysis=a=' . $this->analysisFile()])[0]);
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string}
     */
    private function score(array $args): array
    {
        $script = dirname(__DIR__, 3) . '/benchmarks/real-apps/score.php';
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        $lines = [];
        $code = 0;
        exec($command, $lines, $code);

        return [$code, implode("\n", $lines)];
    }

    private function truthFile(): string
    {
        return $this->write([
            'schema' => 'peak-sim-truth/1',
            'apps' => ['a' => ['commit' => str_repeat('a', 40)]],
            'routes' => [
                ['id' => 'a GET /heavy', 'app' => 'a', 'method' => 'GET', 'uri' => '/heavy', 'sets' => ['round1-target'], 'axes' => ['query' => ['growth' => 'O(N)', 'confidence' => 'high']]],
                ['id' => 'a GET /light', 'app' => 'a', 'method' => 'GET', 'uri' => '/light', 'sets' => ['round1-target'], 'axes' => ['query' => ['growth' => 'O(N)', 'confidence' => 'high']]],
            ],
        ]);
    }

    private function analysisFile(): string
    {
        return $this->write(['routes' => [
            ['route' => '/heavy', 'methods' => ['GET'], 'query' => ['growth' => 'O(N)'], 'memory' => []],
            ['route' => '/light', 'methods' => ['GET'], 'query' => ['growth' => 'O(1)'], 'memory' => []],
        ]]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(array $data): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'peak-sim-bench-');
        file_put_contents($path, json_encode($data));
        $this->files[] = $path;

        return $path;
    }
}
