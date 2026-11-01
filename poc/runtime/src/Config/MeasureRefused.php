<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Config;

/**
 * Thrown when a safety condition is not met. The measurement must not start (exit code 10).
 */
final class MeasureRefused extends \RuntimeException
{
    public const EXIT_CODE = 10;
}
