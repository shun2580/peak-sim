<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Input;

use PeakSim\Input\ProcessResult;
use PeakSim\Input\ProcessRunner;

/**
 * A ProcessRunner double: records every call and answers with a canned result, or delegates to another runner.
 */
final class ScriptedProcessRunner implements ProcessRunner
{
    /** @var list<array{commandLine: string, cwd: string, timeout: int}> */
    public array $calls = [];

    /** @param \Closure(string): ProcessResult $answer receives the command line */
    private function __construct(private readonly \Closure $answer)
    {
    }

    public static function answering(ProcessResult $result): self
    {
        return new self(static fn (string $commandLine): ProcessResult => $result);
    }

    /** @param \Closure(string): ProcessResult $answer */
    public static function with(\Closure $answer): self
    {
        return new self($answer);
    }

    /** Runs the real thing but remembers what was asked. */
    public static function recording(ProcessRunner $inner): self
    {
        $self = null;
        $self = new self(static function (string $commandLine) use ($inner, &$self): ProcessResult {
            $last = $self->calls[array_key_last($self->calls)];

            return $inner->run($commandLine, $last['cwd'], $last['timeout']);
        });

        return $self;
    }

    public function run(string $commandLine, string $cwd, int $timeoutSeconds = 120): ProcessResult
    {
        $this->calls[] = ['commandLine' => $commandLine, 'cwd' => $cwd, 'timeout' => $timeoutSeconds];

        return ($this->answer)($commandLine);
    }
}
