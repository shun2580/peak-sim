<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Load;

/** Every file the simulator reads or writes goes through this, so a test can record the order. */
interface SimulateIo
{
    /** @throws \RuntimeException when the file cannot be read */
    public function read(string $path): string;

    /** @throws \RuntimeException when the file cannot be written */
    public function write(string $path, string $content): void;

    public function exists(string $path): bool;
}
