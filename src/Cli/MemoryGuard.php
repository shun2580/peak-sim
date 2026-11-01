<?php

declare(strict_types=1);

namespace PeakSim\Cli;

/**
 * Ends an out-of-memory death with an explanation (D41). PHP reports "Allowed memory size … exhausted" as a fatal
 * error and then the process is gone; without this the user sees a bare PHP message and exit code 255.
 *
 * After such an error the shutdown function has little memory to work with, so a block is reserved at start and
 * released first thing in the shutdown function (cheap insurance: a plain run showed PHP leaves room for the message
 * either way, but a class to autoload or a bigger message would not be certain to fit). Only that one fatal error is explained: for any other,
 * the advice to raise the limit would point at the wrong thing.
 *
 * This file depends on nothing, so that bin/peak-sim can load it before the autoloader (an overflow while the
 * autoloader runs is then explained as well).
 */
final class MemoryGuard
{
    /** The exit code for "out of memory". Not 0, 2, 3 or 4 (Application), and not 255 (PHP's own fatal error). */
    public const EXIT_CODE = 5;

    private const RESERVE_BYTES = 65536;

    private static ?string $reserve = null;

    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        // A real allocation (not a literal) that nothing else shares: freeing it gives the shutdown function room.
        self::$reserve = str_repeat('x', self::RESERVE_BYTES);
        register_shutdown_function([self::class, 'onShutdown']);
    }

    /** @internal registered as the shutdown function */
    public static function onShutdown(): void
    {
        self::$reserve = null;
        $text = self::advice(error_get_last(), (string) ini_get('memory_limit'));
        if ($text === null) {
            return;
        }
        @fwrite(STDERR, $text);
        exit(self::EXIT_CODE);
    }

    /**
     * The text for standard error when the last error is the memory limit being exhausted, otherwise null.
     *
     * @param array{type: int, message: string, file: string, line: int}|null $error as error_get_last() returns it
     */
    public static function advice(?array $error, string $limit): ?string
    {
        if ($error === null || ($error['type'] ?? null) !== E_ERROR || !str_starts_with($error['message'] ?? '', 'Allowed memory size')) {
            return null;
        }

        return "peak-sim: out of memory: PHP's memory_limit ({$limit}) was used up before the analysis finished.\n"
            . "No report was completed. Run it again with a larger limit, for example --memory-limit=1G\n"
            . "(or raise memory_limit in php.ini). The tool never raises the limit by itself.\n";
    }
}
