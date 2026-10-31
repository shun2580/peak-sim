<?php

declare(strict_types=1);

namespace PeakSim\Tests\Support;

/**
 * Temporary directories for tests that copy or rewrite an app. Everything lives under the system
 * temp directory with a fixed prefix, and only such directories are ever removed.
 */
final class MiniAppWorkspace
{
    public const PREFIX = 'peak-sim-t-';

    /** Top-level entries of an app that the analysis never reads and that are large or secret. */
    public const SKIPPED = ['vendor', 'node_modules', 'storage', '.env', '.git'];

    public static function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function fixtureApp(): string
    {
        return self::projectRoot() . '/fixtures/laravel-app';
    }

    public static function fixtureExport(): string
    {
        return self::projectRoot() . '/fixtures/artisan-export';
    }

    public static function expectedJson(): string
    {
        return self::projectRoot() . '/fixtures/expected.json';
    }

    public static function appsDir(): string
    {
        return self::projectRoot() . '/tests/Fixtures/Apps';
    }

    /** Creates an empty directory and returns its path. */
    public static function make(string $label = 'w'): string
    {
        $path = sys_get_temp_dir() . '/' . self::PREFIX . $label . '-' . bin2hex(random_bytes(6));
        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            throw new \RuntimeException('cannot create a temporary directory');
        }

        return $path;
    }

    /** Copies a directory tree, leaving out SKIPPED top-level entries and, for apps, nothing else. */
    public static function copyTree(string $from, string $to, bool $skipTopLevel = true): void
    {
        if (!is_dir($to) && !mkdir($to, 0777, true) && !is_dir($to)) {
            throw new \RuntimeException('cannot create ' . basename($to));
        }
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if ($skipTopLevel && in_array($entry, self::SKIPPED, true)) {
                continue;
            }
            $source = $from . '/' . $entry;
            if (is_dir($source) && !is_link($source)) {
                self::copyTree($source, $to . '/' . $entry, false);
            } else {
                copy($source, $to . '/' . $entry);
            }
        }
    }

    /** Copies an app into a fresh temporary directory and returns that directory. */
    public static function copyApp(string $appRoot, string $label = 'app'): string
    {
        $copy = self::make($label);
        self::copyTree($appRoot, $copy);

        return $copy;
    }

    public static function read(string $root, string $relative): string
    {
        $content = file_get_contents($root . '/' . $relative);
        if ($content === false) {
            throw new \RuntimeException("cannot read {$relative}");
        }

        return $content;
    }

    public static function write(string $root, string $relative, string $content): void
    {
        $path = $root . '/' . $relative;
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0777, true) && !is_dir(dirname($path))) {
            throw new \RuntimeException("cannot create the directory of {$relative}");
        }
        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException("cannot write {$relative}");
        }
    }

    /** Removes a directory made by make(). Refuses anything else. */
    public static function remove(string $path): void
    {
        $base = basename($path);
        if (dirname($path) !== rtrim(sys_get_temp_dir(), '/') || !str_starts_with($base, self::PREFIX) || !is_dir($path)) {
            return;
        }
        self::removeInside($path);
        rmdir($path);
    }

    private static function removeInside(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            if (is_dir($child) && !is_link($child)) {
                self::removeInside($child);
                rmdir($child);
            } else {
                unlink($child);
            }
        }
    }
}
