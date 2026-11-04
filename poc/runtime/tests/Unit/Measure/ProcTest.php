<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Measure;

use PeakSim\Runtime\Measure\Proc;
use PHPUnit\Framework\TestCase;

final class ProcTest extends TestCase
{
    private const SLEEPER = [PHP_BINARY, '-r', 'sleep(30);'];

    public function test_the_hook_runs_once_after_a_time_out_and_its_note_is_added_to_stderr(): void
    {
        $calls = 0;
        $started = microtime(true);
        [$code, , $err] = Proc::run(self::SLEEPER, ['PATH' => '/usr/bin:/bin:/usr/local/bin'], 1, function () use (&$calls): string {
            ++$calls;

            return 'killed 1 statement(s)';
        });

        $this->assertSame(Proc::TIMED_OUT, $code);
        $this->assertSame(1, $calls);
        $this->assertStringContainsString('timed out after 1 seconds', $err);
        $this->assertStringEndsWith('killed 1 statement(s)', $err);
        $this->assertLessThan(10, microtime(true) - $started);
    }

    public function test_a_hook_that_fails_is_noted_and_the_time_out_is_still_reported(): void
    {
        [$code, , $err] = Proc::run(self::SLEEPER, ['PATH' => '/usr/bin:/bin:/usr/local/bin'], 1, static function (): string {
            throw new \RuntimeException('no server');
        });

        $this->assertSame(Proc::TIMED_OUT, $code);
        $this->assertStringContainsString('the cleanup after the time-out failed: no server', $err);
    }

    public function test_the_hook_does_not_run_when_the_child_finishes_in_time(): void
    {
        $calls = 0;
        [$code, $out] = Proc::run([PHP_BINARY, '-r', 'echo "done";'], ['PATH' => '/usr/bin:/bin:/usr/local/bin'], 20, function () use (&$calls): string {
            ++$calls;

            return '';
        });

        $this->assertSame(0, $code);
        $this->assertSame('done', $out);
        $this->assertSame(0, $calls);
    }
}
