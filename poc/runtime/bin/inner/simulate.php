<?php

declare(strict_types=1);

// Reads an estimate and a scenario file, writes the loaded input (and, once T2/T3 are connected, the prediction). No Docker, no database.

use PeakSim\Runtime\Simulate\SimulateCommand;

require __DIR__ . '/../../bootstrap.php';

exit((new SimulateCommand(STDOUT, STDERR))->run(array_slice($argv, 1)));
