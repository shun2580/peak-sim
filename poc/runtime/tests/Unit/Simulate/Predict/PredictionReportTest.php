<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Simulate\Predict;

use PeakSim\Runtime\Simulate\Predict\AccuracyRecord;
use PeakSim\Runtime\Simulate\Predict\MemoryAccuracy;
use PeakSim\Runtime\Simulate\Predict\PredictionReport;
use PeakSim\Runtime\Simulate\Predict\PredictionText;
use PHPUnit\Framework\TestCase;

final class PredictionReportTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function loaded(float $unknownRps = 0.0): array
    {
        $routes = [PredictFixtures::route('GET /a', 10.0, 5.0, 1000.0), PredictFixtures::route('GET /b', 5.0, 2.0, 400.0, 4000.0)];
        if ($unknownRps > 0.0) {
            $routes[] = PredictFixtures::unknown('GET /u', $unknownRps);
        }

        return [
            'schema_version' => '0.1',
            'app' => 'demo',
            'estimate_at' => 200000,
            'resources' => ['cores' => 2, 'threshold' => 0.8, 'php_memory_limit_bytes' => 134217728],
            'scenarios' => [
                ['id' => 'cal-1', 'kind' => 'calibration', 'tables' => ['items' => 10000], 'observed' => ['db_cpu_utilization' => 0.21], 'unknown_tables' => [], 'zero_rps_routes' => [], 'routes' => $routes],
                ['id' => 'peak-1', 'kind' => 'target', 'tables' => ['items' => 100000], 'unknown_tables' => [], 'zero_rps_routes' => [], 'routes' => $routes],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function build(array $loaded, array $accuracy): array
    {
        $memory = ['GET /a' => ['table' => 'items', 'a' => 0.0, 'b' => 1000.0, 'k' => 1.0], 'GET /b' => null];
        $acc = MemoryAccuracy::fromArray(['schema_version' => '1', 'source' => 'test', 'within' => ['GET /a']]);

        return PredictionReport::build($loaded, PredictFixtures::twoCoefficient(0.001, 0.0001, 0.0005, 0.0002), $memory, $acc, $accuracy);
    }

    public function testOnlyTargetScenariosArePredictedAndNothingIsObserved(): void
    {
        $r = self::build(self::loaded(), AccuracyRecord::notChecked());
        self::assertSame(['peak-1'], array_column($r['scenarios'], 'id'));
        self::assertStringNotContainsString('observed', json_encode($r, JSON_THROW_ON_ERROR));
    }

    public function testTheOutputHasEveryRequiredItem(): void
    {
        $r = self::build(self::loaded(), AccuracyRecord::notChecked());
        foreach (['low_coverage_scenarios', 'calibration', 'scenarios', 'peak_memory', 'accuracy_on_this_app', 'sources', 'n_marks'] as $key) {
            self::assertArrayHasKey($key, $r);
        }
        $s = $r['scenarios'][0];
        foreach (['utilization', 'limit_multiplier', 'dominant_routes', 'coverage', 'low_coverage', 'zero_rps_routes', 'unknown_tables', 'sensitivity', 'routes', 'n_marks'] as $key) {
            self::assertArrayHasKey($key, $s);
        }
        self::assertArrayHasKey('residuals', $r['calibration']['fits'][0]);
        self::assertSame('two_coefficient', $r['calibration']['method']);
        self::assertSame('not_checked', $r['accuracy_on_this_app']['status']);
        self::assertSame(107375, $r['peak_memory']['routes'][0]['n']);
    }

    public function testTheSameInputGivesTheSameJson(): void
    {
        $a = json_encode(self::build(self::loaded(), AccuracyRecord::notChecked()), JSON_PRESERVE_ZERO_FRACTION);
        $b = json_encode(self::build(self::loaded(), AccuracyRecord::notChecked()), JSON_PRESERVE_ZERO_FRACTION);
        self::assertSame($a, $b);
    }

    public function testTheTableStartsWithTheLowCoverageWarning(): void
    {
        $text = PredictionText::render(self::build(self::loaded(7.0), AccuracyRecord::notChecked()));
        self::assertStringStartsWith('WARNING low_coverage', $text);
        self::assertStringContainsString('peak-1   [LOW COVERAGE]', $text);
        self::assertStringContainsString('accuracy on this app: not checked', $text);
    }

    public function testTheTableHasNoWarningWhenCoverageIsEnough(): void
    {
        $text = PredictionText::render(self::build(self::loaded(), AccuracyRecord::notChecked()));
        self::assertStringStartsWith('peak-sim simulate of demo', $text);
        self::assertStringContainsString('limit        point 1.3x   safe side 0.6x', $text);
        self::assertStringContainsString('N = 107,375 (items)', $text);
    }

    public function testASavedSelfcheckResultIsCarriedOver(): void
    {
        $saved = ['kind' => 'selfcheck-result', 'app' => 'demo', 'target_scale' => 20000, 'checked_at' => '2026-11-09T10:00:00+09:00', 'summary' => ['verdict' => 'pass', 'routes' => 12, 'marked_routes' => 2, 'unmarked_routes' => 10, 'quantities' => []]];
        $a = AccuracyRecord::fromResult($saved);
        self::assertSame('checked', $a['status']);
        self::assertSame('pass', $a['verdict']);
        $text = PredictionText::render(self::build(self::loaded(), $a));
        self::assertStringContainsString('selfcheck 2026-11-09T10:00:00+09:00 at 20,000 rows, demo: PASS', $text);
    }

    public function testAFileThatIsNotASelfcheckResultIsAnErrorNotNotChecked(): void
    {
        $path = sys_get_temp_dir() . '/peak-accuracy-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, '{"kind": "something-else"}');
        try {
            $this->expectException(\InvalidArgumentException::class);
            AccuracyRecord::load($path);
        } finally {
            unlink($path);
        }
    }

    public function testNoFileIsNotChecked(): void
    {
        self::assertSame('not_checked', AccuracyRecord::load(null)['status']);
        self::assertSame('not_checked', AccuracyRecord::load('/nonexistent/selfcheck-result.json')['status']);
    }
}
