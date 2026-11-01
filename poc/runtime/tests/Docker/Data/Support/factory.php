<?php

declare(strict_types=1);

// Test support (runs INSIDE the sealed container): boots the app against an existing measurement database and
// creates rows through the model's own factory with FactoryRunner.
//
//   php factory.php <scale> <model class> <count>

use PeakSim\Runtime\Boot\AppBooter;
use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Data\FactoryRunner;

require dirname(__DIR__, 4) . '/bootstrap.php';

[$scale, $model, $count] = [(int) ($argv[1] ?? 0), (string) ($argv[2] ?? ''), (int) ($argv[3] ?? 1)];
$config = MeasureConfig::fromEnv(getenv());
$booted = (new AppBooter())->boot($config, $scale);

$result = (new FactoryRunner())->run($model, $count);
$rows = class_exists($model) ? (new $model())->newQuery()->count() : null;

echo json_encode(['created' => $result->created, 'reason' => $result->reason, 'detail' => $result->detail, 'rows' => $rows], JSON_UNESCAPED_SLASHES), "\n";
