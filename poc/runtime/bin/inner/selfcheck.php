<?php

declare(strict_types=1);

// The PHP half of bin/selfcheck: seal the estimate, open the gate to the measurement, judge. No Docker, no database.

use PeakSim\Runtime\Selfcheck\SelfcheckCommand;

require __DIR__ . '/../../bootstrap.php';

exit((new SelfcheckCommand(STDOUT, STDERR))->run(array_slice($argv, 1)));
