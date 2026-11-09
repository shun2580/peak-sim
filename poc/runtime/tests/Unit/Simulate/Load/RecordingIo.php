<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Simulate\Load;

use PeakSim\Runtime\Simulate\Load\SimulateIo;

/** Files in memory, and the order in which they were read and written. */
final class RecordingIo implements SimulateIo
{
    /** @var list<string> "read <path>" / "write <path>" in the order they happened */
    public array $events = [];

    /** @param array<string, string> $files */
    public function __construct(public array $files = [])
    {
    }

    public function read(string $path): string
    {
        $this->events[] = 'read ' . $path;
        if (!isset($this->files[$path])) {
            throw new \RuntimeException('cannot read ' . $path);
        }

        return $this->files[$path];
    }

    public function write(string $path, string $content): void
    {
        $this->events[] = 'write ' . $path;
        $this->files[$path] = $content;
    }

    public function exists(string $path): bool
    {
        return isset($this->files[$path]);
    }
}
