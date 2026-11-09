<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Boot;

/**
 * The only place the target app writes to during a measurement: a directory under the system temp dir (a tmpfs in
 * the sealed container). Holds storage/, the compiled views, and the package/service manifests.
 * It is removed at the end of the process, and only ever below its own prefix.
 */
final class ScratchDir
{
    private const PREFIX = 'peak-measure-';

    private function __construct(public readonly string $path)
    {
    }

    public static function create(string $runId, ?string $base = null): self
    {
        $base ??= sys_get_temp_dir();

        return self::make(rtrim($base, '/') . '/' . self::PREFIX . $runId . '-' . getmypid() . '-' . bin2hex(random_bytes(3)));
    }

    /**
     * A scratch directory at a path the caller fixes, shared by every process that names the same path (the lab's
     * php-fpm workers, which would otherwise compile the views again for every request). The caller owns it: nothing
     * registers its removal. The path must be absolute, without "..", and its last part must start with the prefix
     * `peak-measure-`, so `remove()` can never be pointed at anything else.
     *
     * @throws BootFailure
     */
    public static function fixed(string $path): self
    {
        if (!str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, "\0") || !str_starts_with(basename($path), self::PREFIX)) {
            throw new BootFailure(sprintf('the fixed scratch directory must be an absolute path whose last part starts with %s: %s', self::PREFIX, $path));
        }

        return self::make(rtrim($path, '/'));
    }

    private static function make(string $path): self
    {
        foreach (['', '/storage/framework/cache/data', '/storage/framework/sessions', '/storage/framework/views', '/storage/framework/testing', '/storage/logs', '/storage/app/public', '/bootstrap-cache', '/views'] as $sub) {
            if (!is_dir($path . $sub) && !mkdir($path . $sub, 0700, true) && !is_dir($path . $sub)) {
                throw new BootFailure(sprintf('cannot create the scratch directory %s (is the temp dir writable?)', $path . $sub));
            }
        }

        return new self($path);
    }

    public function storagePath(): string
    {
        return $this->path . '/storage';
    }

    public function viewsPath(): string
    {
        return $this->path . '/views';
    }

    public function bootstrapCachePath(string $file): string
    {
        return $this->path . '/bootstrap-cache/' . $file;
    }

    /** Removes the directory. Refuses any path that is not a scratch directory made by create(). */
    public function remove(): void
    {
        $name = basename($this->path);
        if (!str_starts_with($name, self::PREFIX) || !is_dir($this->path) || is_link($this->path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($this->path);
    }
}
