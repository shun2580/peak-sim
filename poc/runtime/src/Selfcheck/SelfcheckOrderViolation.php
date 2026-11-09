<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Selfcheck;

/** A measurement was about to start (or be judged) without the sealed estimate coming first. */
final class SelfcheckOrderViolation extends \RuntimeException
{
}
