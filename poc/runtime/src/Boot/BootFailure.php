<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Boot;

/** The target app could not be started (exit code 12). */
final class BootFailure extends \RuntimeException
{
    public const EXIT_CODE = 12;
}
