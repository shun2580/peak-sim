<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

/** Runs one child process with a time limit, reading both pipes so neither can fill and block it. */
final class Proc
{
    /** The exit code reported when the limit was hit (the same as `timeout(1)`). */
    public const TIMED_OUT = 124;

    /**
     * @param list<string>          $command
     * @param array<string, string> $env
     * @param \Closure|null         $onTimeout called once, after the child was stopped and reaped, when the limit was hit; what it
     *                                         returns (a short note) is added to stderr. A failure inside it is noted, never thrown.
     *
     * @return array{int, string, string} [exit code, stdout, stderr]
     */
    public static function run(array $command, array $env, int $timeoutSeconds, ?\Closure $onTimeout = null): array
    {
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($process)) {
            return [127, '', 'could not start ' . $command[0]];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = '';
        $err = '';
        $deadline = microtime(true) + $timeoutSeconds;
        $timedOut = false;
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        while ($open !== []) {
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                $timedOut = true;
                break;
            }
            $read = array_values($open);
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 0, (int) min(200_000, $left * 1e6));
            if ($ready === false) {
                break;
            }
            foreach ($read as $stream) {
                $chunk = fread($stream, 65536);
                $which = $stream === $pipes[1] ? 1 : 2;
                if ($chunk === '' || $chunk === false) {
                    if (feof($stream)) {
                        unset($open[$which]);
                    }

                    continue;
                }
                if ($which === 1) {
                    $out .= $chunk;
                } else {
                    $err .= $chunk;
                }
            }
        }

        if ($timedOut) {
            proc_terminate($process, 9); // our own child only
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if (!$timedOut) {
            return [$code, $out, $err];
        }
        $note = '';
        if ($onTimeout !== null) {
            try {
                $note = (string) $onTimeout();
            } catch (\Throwable $e) {
                $note = 'the cleanup after the time-out failed: ' . $e->getMessage();
            }
        }

        return [self::TIMED_OUT, $out, $err . sprintf("\ntimed out after %d seconds", $timeoutSeconds) . ($note === '' ? '' : "\n" . $note)];
    }
}
