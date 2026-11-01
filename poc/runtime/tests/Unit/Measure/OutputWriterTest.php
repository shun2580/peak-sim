<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Measure;

use PeakSim\Runtime\Measure\Driver;
use PeakSim\Runtime\Measure\DriverResult;
use PeakSim\Runtime\Measure\OutputWriter;
use PeakSim\Runtime\Measure\RouteTask;
use PeakSim\Runtime\Report\RunReport;
use PeakSim\Runtime\Run\Attempt;

final class OutputWriterTest extends PlannerTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/peak-output-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    private static function sample(): DriverResult
    {
        $spec = self::spec('/ok/eager', ['query' => ['growth' => 'O(1)', 'lower_bound' => null, 'confidence' => 'high']]);
        $attempts = [];
        foreach ([100, 1000, 10000] as $scale) {
            foreach ([Attempt::WARMUP, Attempt::COLD] as $phase) {
                $attempts[] = new Attempt('/ok/eager', 'GET', $scale, 'N', $phase, 200, 4, ['mysql' => 4], ['orders'], 100 + $scale, 1000, false, 'ok', null);
            }
        }
        $report = new RunReport();
        $report->add(RunReport::rowFor($spec, $attempts));

        return new DriverResult(0, null, $report, $attempts, [['point' => 'ord:100', 'build_seconds' => 1.0, 'measure_seconds' => 2.0, 'routes' => 1]], [['point' => 'ord:100', 'database' => 'peak_sim_measure_x', 'rows' => ['orders' => 100]]]);
    }

    public function test_it_writes_the_record_files(): void
    {
        $files = (new OutputWriter($this->dir))->write(self::sample(), ['data' => 'seeder'], [new RouteTask(self::spec('/ok/eager'), '/ok/eager')], null);

        $this->assertCount(5, $files);
        foreach (['attempts.jsonl', 'routes.jsonl', 'report.md', 'tasks.json', 'summary.json'] as $name) {
            $this->assertFileExists($this->dir . '/' . $name);
        }
        $this->assertFileDoesNotExist($this->dir . '/comparison.md');
        $this->assertCount(6, array_filter(explode("\n", (string) file_get_contents($this->dir . '/attempts.jsonl'))));
        $summary = json_decode((string) file_get_contents($this->dir . '/summary.json'), true);
        $this->assertSame(0, $summary['exit']);
        $this->assertSame('seeder', $summary['meta']['data']);
        $this->assertSame(1, $summary['summary']['routes']);
        $this->assertSame(['orders' => 100], $summary['builds'][0]['rows']);
    }

    public function test_a_rebuild_after_a_request_changed_the_database_stays_in_the_record(): void
    {
        $base = self::sample();
        $result = new DriverResult(0, null, $base->report, $base->attempts, $base->timings, [['point' => 'ord:100', 'rebuilt_after' => '/probe/ddl', 'database' => 'peak_sim_measure_x', 'rows' => []]]);

        (new OutputWriter($this->dir))->write($result, [], [], null);

        $summary = json_decode((string) file_get_contents($this->dir . '/summary.json'), true);
        $this->assertSame('/probe/ddl', $summary['builds'][0]['rebuilt_after']);
    }

    public function test_with_expectations_it_puts_them_beside_the_static_analysis_and_the_measurement(): void
    {
        (new OutputWriter($this->dir))->write(self::sample(), [], [], [['route' => '/ok/eager', 'group' => 'ok', 'query' => 'O(1)']]);

        $table = json_decode((string) file_get_contents($this->dir . '/comparison.json'), true);
        $this->assertSame('/ok/eager', $table[0]['route']);
        $this->assertSame('O(1)', $table[0]['expected']);
        $this->assertSame('O(1)', $table[0]['static']);
        $this->assertSame('O(1)', $table[0]['runtime_growth'] ?? null);
        $this->assertSame('agree', $table[0]['relation']);
        $this->assertSame('match', $table[0]['vs_expected']);
        $this->assertFileExists($this->dir . '/comparison.md');
    }
}
