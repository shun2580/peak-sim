<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Load;

final class NativeIo implements SimulateIo
{
    public function read(string $path): string
    {
        $raw = is_file($path) ? @file_get_contents($path) : false;
        if ($raw === false) {
            throw new \RuntimeException('cannot read ' . $path);
        }

        return $raw;
    }

    public function write(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create ' . $dir);
        }
        if (@file_put_contents($path, $content) === false) {
            throw new \RuntimeException('cannot write ' . $path);
        }
    }

    public function exists(string $path): bool
    {
        return is_file($path);
    }
}
