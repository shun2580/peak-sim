<?php

declare(strict_types=1);

namespace PeakSim\Tests\Acceptance\Cli;

use PeakSim\Cli\Application;
use PeakSim\Name;
use PHPUnit\Framework\TestCase;

/**
 * Exit code 4: a bug in the tool, not in the input. The CLI object is run in this process with in-memory
 * streams so that a failure can be forced from outside.
 */
final class InternalErrorTest extends TestCase
{
    public function testAnUnexpectedExceptionExitsWithFourAndIsReportedOnStandardError(): void
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $this->assertIsResource($out);
        $this->assertIsResource($err);
        $app = new Application(static function (): never {
            throw new \LogicException('the walker lost its way');
        });

        $code = $app->run([Name::TOOL_NAME, 'analyze', sys_get_temp_dir(), '--export-dir=' . sys_get_temp_dir()], $out, $err);

        rewind($out);
        rewind($err);
        $this->assertSame(4, $code);
        $this->assertSame('', stream_get_contents($out));
        $message = (string) stream_get_contents($err);
        $this->assertStringContainsString('internal error', $message);
        $this->assertStringContainsString('the walker lost its way', $message);
        $this->assertStringContainsString(Name::TOOL_NAME, $message);
    }

    public function testRunReturnsTheCodeAndWritesOnlyToTheStreamsItIsGiven(): void
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $this->assertIsResource($out);
        $this->assertIsResource($err);

        $code = (new Application())->run([Name::TOOL_NAME, 'nonsense'], $out, $err);

        rewind($out);
        rewind($err);
        $this->assertSame(2, $code);
        $this->assertSame('', stream_get_contents($out));
        $this->assertNotSame('', stream_get_contents($err));
    }
}
