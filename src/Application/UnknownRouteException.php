<?php

declare(strict_types=1);

namespace PeakSim\Application;

/** analyzeRoute() was asked for a route that does not exist or is not analysed. A mistake of the caller, not of the input. */
final class UnknownRouteException extends \InvalidArgumentException
{
}
