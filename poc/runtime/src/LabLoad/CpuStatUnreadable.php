<?php

declare(strict_types=1);

namespace PeakSim\Runtime\LabLoad;

/**
 * The container's CPU time could not be read. The utilization is never replaced by an instantaneous figure; the run stops.
 */
final class CpuStatUnreadable extends \RuntimeException
{
}
