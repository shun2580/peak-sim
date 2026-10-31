<?php

declare(strict_types=1);

namespace PeakSim\Input;

/**
 * Runs a command line through the shell with proc_open (no extra dependency). Standard output and standard error
 * are read side by side with stream_select: reading one to the end first would stall the child once the other pipe
 * is full (the pipe holds about 64KB) and freeze both of us.
 */
final class ProcOpenRunner implements ProcessRunner
{
    private const CHUNK = 65536;
    private const POLL_MICROSECONDS = 200000;

    public function run(string $commandLine, string $cwd, int $timeoutSeconds = 120): ProcessResult
    {
        if (!is_dir($cwd)) {
            throw new ArtisanFailedException("working directory does not exist: {$cwd}");
        }

        $pipes = [];
        $process = proc_open($commandLine, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        if (!is_resource($process)) {
            throw new ArtisanFailedException('could not start the command');
        }

        // Nobody types into artisan: a command that reads stdin sees end of file instead of waiting.
        fclose($pipes[0]);
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        foreach ($open as $stream) {
            stream_set_blocking($stream, false);
        }
        $buffers = [1 => '', 2 => ''];
        $seconds = max(1, $timeoutSeconds);
        $deadline = microtime(true) + $seconds;

        while ($open !== []) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $this->abort($process, $open, $seconds);
            }

            $read = $open;
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 0, (int) min(self::POLL_MICROSECONDS, $remaining * 1000000));
            if ($ready === false) {
                $this->abort($process, $open, $seconds, 'waiting for the command output failed');
            }

            foreach ($open as $id => $stream) {
                if (!in_array($stream, $read, true)) {
                    continue;
                }
                $chunk = fread($stream, self::CHUNK);
                if ($chunk !== false && $chunk !== '') {
                    $buffers[$id] .= $chunk;
                }
                if (feof($stream)) {
                    fclose($stream);
                    unset($open[$id]);
                }
            }
        }

        $exitCode = $this->waitForExit($process, $deadline, $seconds);

        return new ProcessResult($exitCode, $buffers[1], $buffers[2]);
    }

    /** @param resource $process */
    private function waitForExit($process, float $deadline, int $seconds): int
    {
        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                // proc_close() answers -1 once proc_get_status() has seen the exit, so take the code from here.
                proc_close($process);

                return $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'];
            }
            if (microtime(true) >= $deadline) {
                $this->abort($process, [], $seconds);
            }
            usleep(10000);
        }
    }

    /**
     * @param resource $process
     * @param array<int, resource> $openStreams
     *
     * @return never
     */
    private function abort($process, array $openStreams, int $seconds, ?string $reason = null): never
    {
        proc_terminate($process, 9);
        foreach ($openStreams as $stream) {
            fclose($stream);
        }
        proc_close($process);

        throw new ArtisanFailedException($reason ?? "the command timed out after {$seconds} seconds and was stopped");
    }
}
