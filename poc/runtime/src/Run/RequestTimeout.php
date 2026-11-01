<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Run;

/** Thrown from the alarm handler when one request runs past its limit. */
final class RequestTimeout extends \RuntimeException
{
}
