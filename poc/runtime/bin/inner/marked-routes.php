<?php

declare(strict_types=1);

// Lists the marked routes of an explain.json (simulator-spec.md section 7.1). Needs no Docker and no database.

use PeakSim\Runtime\Estimate\MarkedRoutesCommand;

require __DIR__ . '/../../bootstrap.php';

exit((new MarkedRoutesCommand(STDOUT, STDERR))->run(array_slice($argv, 1)));
