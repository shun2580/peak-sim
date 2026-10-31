<?php

declare(strict_types=1);

namespace PeakSim\Model;

/** A place in the analysed app. The file is relative to the app root; never an absolute path. */
final readonly class Site
{
    public function __construct(
        public string $file,
        public int $line,
        public string $note = '',
    ) {
    }

    public function fingerprint(): string
    {
        return $this->file . ':' . $this->line;
    }
}
