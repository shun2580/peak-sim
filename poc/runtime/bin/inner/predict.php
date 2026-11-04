<?php

declare(strict_types=1);

// Puts a size into the fitted formulas of bin/fit and prints predictions.json. Needs no Docker and no database.

use PeakSim\Runtime\Judge\PredictCommand;

require __DIR__ . '/../../bootstrap.php';

exit((new PredictCommand(STDOUT, STDERR))->run(array_slice($argv, 1)));
