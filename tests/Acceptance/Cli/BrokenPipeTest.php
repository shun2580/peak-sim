<?php

declare(strict_types=1);

namespace PeakSim\Tests\Acceptance\Cli;

use PeakSim\Cli\Application;
use PeakSim\Input\ProcOpenRunner;
use PeakSim\Tests\Support\MiniAppWorkspace;

/**
 * `peak-sim analyze … | head`: when the reader closes first the write fails with EPIPE. That is the reader's
 * choice, not an error of the tool: no notice, and the exit code stays what the analysis decided.
 */
final class BrokenPipeTest extends CliTestCase
{
    public function testAClosedStandardOutputIsNotReportedAndTheExitCodeIsUnchanged(): void
    {
        [$stdout, $reader] = $this->socketPair();
        fclose($reader);
        $stderr = fopen('php://memory', 'w+');
        $this->assertIsResource($stderr);

        $code = (new Application())->run(['peak-sim', 'analyze', MiniAppWorkspace::fixtureApp(), $this->exportOption()], $stdout, $stderr);

        rewind($stderr);
        $this->assertSame(0, $code);
        $this->assertSame('', stream_get_contents($stderr));
    }

    public function testAClosedStandardOutputIsQuietForTheHelpToo(): void
    {
        [$stdout, $reader] = $this->socketPair();
        fclose($reader);
        $stderr = fopen('php://memory', 'w+');
        $this->assertIsResource($stderr);

        $this->assertSame(0, (new Application())->run(['peak-sim', '--help'], $stdout, $stderr));
        rewind($stderr);
        $this->assertSame('', stream_get_contents($stderr));
    }

    public function testPipingIntoAReaderThatQuitsPrintsNoNoticeOnTheRealBinary(): void
    {
        $binary = MiniAppWorkspace::projectRoot() . '/bin/peak-sim';
        // `true` quits at once, long before PHP has a report to write. PIPESTATUS[0] is the tool's own exit code.
        $script = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($binary) . ' analyze '
            . escapeshellarg(MiniAppWorkspace::fixtureApp()) . ' ' . escapeshellarg($this->exportOption())
            . ' | true; echo "exit=${PIPESTATUS[0]}" >&2';

        $result = (new ProcOpenRunner())->run('bash -c ' . escapeshellarg($script), MiniAppWorkspace::projectRoot(), 120);

        $this->assertStringNotContainsString('Broken pipe', $result->stderr);
        $this->assertStringNotContainsString('fwrite', $result->stderr);
        $this->assertStringContainsString('exit=0', $result->stderr);
    }

    /** @return array{0: resource, 1: resource} */
    private function socketPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);

        return [$pair[0], $pair[1]];
    }
}
