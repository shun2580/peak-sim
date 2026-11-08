<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Explain;

use PeakSim\Runtime\Explain\CaptureFile;
use PHPUnit\Framework\TestCase;

/** The reader against the example of `CAPTURE_FORMAT.md` that the capture tests check line by line. */
final class CaptureFileTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function sample(): array
    {
        $file = dirname(__DIR__) . '/Capture/vectors/capture.sample.json';

        return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testItReadsTheRoutesAndTheStatementTypesOfTheSample(): void
    {
        $read = CaptureFile::read(self::sample());

        $this->assertSame(['GET /orders/{id}', 'GET /ping/{id}', 'POST /never/{x}'], $read['routes']);
        $this->assertSame([100, 1000, 10000], $read['scales']);
        $routes = array_values(array_unique(array_column($read['statements'], 'route')));
        $this->assertSame(['GET /orders/{id}', 'GET /ping/{id}'], $routes, 'a route that was not captured has no statement types');
        foreach ($read['statements'] as $s) {
            $this->assertNotSame('', $s['id']);
            $this->assertNotNull($s['sql']);
            $this->assertNull($s['not_explained']);
            $this->assertContains($s['representative_scale'], [1000, 10000], 'the largest scale that ran the type');
        }
    }

    public function testASecretValueOfTheSampleIsMarkedRedacted(): void
    {
        $read = CaptureFile::read(self::sample());

        $redacted = array_values(array_filter($read['statements'], static fn (array $s): bool => $s['bindings_redacted']));
        $this->assertNotEmpty($redacted, 'the sample has an update with a redacted value');
        $this->assertContains(CaptureFile::REDACTED, $redacted[0]['bindings']);
    }

    public function testEveryRepresentativeOfTheSampleGoesThroughTheGateWhenAskedAsAnExplain(): void
    {
        $transport = new RecordingTransport();
        $read = CaptureFile::read(self::sample());
        $sender = new \PeakSim\Runtime\Explain\ExplainSender($transport);

        $results = \PeakSim\Runtime\Explain\ExplainRun::run($sender, $read['statements'], $read['routes']);

        $this->assertCount(count($read['statements']), $results);
        foreach ($transport->sent as $sent) {
            $this->assertStringStartsWith('EXPLAIN FORMAT=JSON ', $sent['sql']);
        }
        $unavailable = array_filter($results, static fn (array $r): bool => $r['unavailable'] !== null);
        foreach ($unavailable as $r) {
            $this->assertStringStartsNotWith('server_refused', (string) $r['unavailable']);
        }
    }
}
