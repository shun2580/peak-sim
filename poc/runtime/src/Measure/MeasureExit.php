<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

/**
 * Exit codes of `bin/measure` (this directory's own scheme; not the official tool's).
 * 10 is also MeasureRefused::EXIT_CODE and 12 BootFailure::EXIT_CODE: the codes of the parts are the codes of the whole.
 */
final class MeasureExit
{
    public const OK = 0;
    public const USAGE = 2;
    public const REFUSED = 10;
    public const PREFLIGHT = 11;
    public const APP_START = 12;
    public const DATA = 13;
    public const CONTAMINATED = 14;
}
