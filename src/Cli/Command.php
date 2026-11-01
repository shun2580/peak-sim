<?php

declare(strict_types=1);

namespace PeakSim\Cli;

/** A parsed `analyze` command line. */
final readonly class Command
{
    public const FORMAT_TABLE = 'table';
    public const FORMAT_JSON = 'json';

    public function __construct(
        public string $appRoot,
        public ?string $artisanCommand,
        public ?string $exportDir,
        public string $format,
        public ?string $route,
        public int $maxDepth,
        public bool $stats,
        public ?string $memoryLimit = null,
    ) {
    }
}
