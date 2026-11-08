<?php

declare(strict_types=1);

// Estimates the rows of every route at a size N from a capture and the EXPLAIN of the database of N rows (candidates H1 to H3).
// Needs no Docker and no database.

use PeakSim\Runtime\Estimate\EstimateCommand;

require __DIR__ . '/../../bootstrap.php';

exit((new EstimateCommand(STDOUT, STDERR))->run(array_slice($argv, 1)));
