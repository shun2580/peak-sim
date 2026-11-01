<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Route;

/** A request field that must be an uploaded file. The booter turns it into `UploadedFile::fake()`. */
final class FileField
{
    public function __construct(public readonly string $kind)
    {
        if (! in_array($kind, ['file', 'image'], true)) {
            throw new \InvalidArgumentException('a file field is a file or an image');
        }
    }
}
