<?php

declare(strict_types=1);

namespace PeakSim\Runtime\LabEntry;

/** Thrown from the lab entry's statement watch, before the statement is sent to the database. */
final class LabWriteRefused extends \RuntimeException
{
}
