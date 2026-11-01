<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Cli;

use PeakSim\Cli\MemoryGuard;
use PHPUnit\Framework\TestCase;

/**
 * When the tool ends with a fatal error, only "Allowed memory size … exhausted" earns the advice. Any other fatal
 * error is somebody else's to explain: guessing would send the user to raise a limit that is not the problem.
 */
final class MemoryGuardTest extends TestCase
{
    public function testAnExhaustedMemoryLimitGetsTheCauseAndTheAdvice(): void
    {
        $error = [
            'type' => E_ERROR,
            'message' => 'Allowed memory size of 8388608 bytes exhausted (tried to allocate 2097152 bytes)',
            'file' => 'x.php',
            'line' => 1,
        ];

        $text = MemoryGuard::advice($error, '8M');

        $this->assertNotNull($text);
        $this->assertStringContainsString('out of memory', $text);
        $this->assertStringContainsString('memory_limit', $text);
        $this->assertStringContainsString('8M', $text);
        $this->assertStringContainsString('--memory-limit=2G', $text);
        $this->assertStringEndsWith("\n", $text);
    }

    public function testNoAdviceWithoutAnError(): void
    {
        $this->assertNull(MemoryGuard::advice(null, '128M'));
    }

    public function testNoAdviceForAnotherFatalError(): void
    {
        $error = ['type' => E_ERROR, 'message' => 'Uncaught Error: Call to undefined function nope()', 'file' => 'x.php', 'line' => 1];

        $this->assertNull(MemoryGuard::advice($error, '128M'));
    }

    public function testNoAdviceForAWarningThatMentionsTheMemorySize(): void
    {
        $error = ['type' => E_WARNING, 'message' => 'Allowed memory size is mentioned in passing', 'file' => 'x.php', 'line' => 1];

        $this->assertNull(MemoryGuard::advice($error, '128M'));
    }

    public function testTheExitCodeIsTheOneTheSchemaDocuments(): void
    {
        $this->assertSame(5, MemoryGuard::EXIT_CODE);
    }
}
