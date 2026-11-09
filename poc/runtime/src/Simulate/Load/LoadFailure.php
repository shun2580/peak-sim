<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Load;

/**
 * The input stops the run at the reading stage. The code is the exit code of `bin/simulate` (D128).
 *
 *   20  the scenario file cannot be read, or is not in the form of section 3.4
 *   21  `schema_version` is not the one this reader knows
 *   22  a scenario names a route the estimate (and the static analysis behind it) does not have
 *   23  a calibration scenario has no `observed`
 *   24  `resources.db_cpu.cores` is missing
 *   25  the estimate directory cannot be read, or its estimate.json has no formulas
 */
final class LoadFailure extends \RuntimeException
{
    public const SCENARIO_INVALID = 20;

    public const SCHEMA_VERSION = 21;

    public const UNKNOWN_ROUTE = 22;

    public const OBSERVED_MISSING = 23;

    public const CORES_MISSING = 24;

    public const ESTIMATE_INVALID = 25;

    public function __construct(public readonly int $exitCode, string $message)
    {
        parent::__construct($message);
    }
}
