<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Capture;

use PeakSim\Runtime\Capture\TapDirectory;
use PeakSim\Runtime\Measure\Driver;
use PHPUnit\Framework\TestCase;

final class TapDirectoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/peak-tapdir-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    /** @param array<string, mixed> $extra */
    private function put(string $method, string $route, int $scale, array $extra = []): void
    {
        $doc = array_replace(['tap_version' => 1, 'route' => $route, 'method' => $method, 'uri' => $route, 'scale' => $scale, 'axis' => 'N', 'statements' => []], $extra);
        file_put_contents(Driver::tapFile($this->dir, $method, $route, $scale), json_encode($doc) . "\n");
    }

    public function testRecordsAreGroupedByRouteAndScale(): void
    {
        $this->put('GET', '/b', 100);
        $this->put('GET', '/a', 1000);
        $this->put('GET', '/a', 100);
        $taps = TapDirectory::read($this->dir);

        self::assertSame(['GET /a', 'GET /b'], array_keys($taps));
        self::assertSame([100, 1000], array_keys($taps['GET /a']));
        self::assertSame(100, $taps['GET /b'][100]['scale']);
    }

    public function testTheSameRouteWithAnotherMethodIsAnotherRoute(): void
    {
        $this->put('GET', '/a', 100);
        $this->put('POST', '/a', 100);

        self::assertSame(['GET /a', 'POST /a'], array_keys(TapDirectory::read($this->dir)));
    }

    public function testARecordOfAnotherAxisAndAnEmptyFileAreLeftOut(): void
    {
        $this->put('GET', '/a', 3, ['axis' => 'M']);
        file_put_contents($this->dir . '/empty.jsonl', '');

        self::assertSame([], TapDirectory::read($this->dir));
    }

    public function testAFileThatIsNotARecordStopsTheReading(): void
    {
        file_put_contents($this->dir . '/x.jsonl', "{\"hello\":1}\n");
        $this->expectException(\RuntimeException::class);
        TapDirectory::read($this->dir);
    }

    public function testAMissingDirectoryHasNoRecords(): void
    {
        self::assertSame([], TapDirectory::read($this->dir . '/nope'));
    }
}
