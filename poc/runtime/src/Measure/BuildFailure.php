<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

/** A measurement database could not be made, migrated or filled. Ends the run with exit code 13. */
final class BuildFailure extends \RuntimeException
{
}
