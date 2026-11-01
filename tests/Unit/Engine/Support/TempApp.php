<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Engine\Support;

/** A throwaway app on disk: files are written under a fresh directory that is removed when the object is released. */
final class TempApp
{
    public readonly string $root;

    /** @param array<string, string> $files app-relative path => contents */
    public function __construct(array $files)
    {
        $this->root = sys_get_temp_dir() . '/peaksim-engine-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
        $files += ['composer.json' => '{"autoload":{"psr-4":{"App\\\\":"app/"}}}'];
        foreach ($files as $path => $code) {
            $full = $this->root . '/' . $path;
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0777, true);
            }
            file_put_contents($full, $code);
        }
    }

    public function __destruct()
    {
        $prefix = sys_get_temp_dir() . '/peaksim-engine-';
        if (!str_starts_with($this->root, $prefix) || !is_dir($this->root)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            // a link to a directory is removed as a file: the directory it points at is not ours to remove
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }
}
