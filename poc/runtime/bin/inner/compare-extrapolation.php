<?php

declare(strict_types=1);

// Checks recorded predictions against a measurement at the predicted size and prints the extrapolation test result.
// Needs no Docker and no database; the same inputs always give the same output.

use PeakSim\Runtime\Judge\CompareExtrapolationCommand;

require __DIR__ . '/../../bootstrap.php';

exit((new CompareExtrapolationCommand(STDOUT, STDERR))->run(array_slice($argv, 1)));
