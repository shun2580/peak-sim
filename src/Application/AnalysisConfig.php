<?php

declare(strict_types=1);

namespace PeakSim\Application;

/** Everything one analysis needs to know. There is no configuration file (S-32 (9)); these come from the command line. */
final class AnalysisConfig
{
    public function __construct(
        public readonly string $appRoot,
        /** How to run artisan, e.g. `docker compose exec app php artisan`. Null: `php artisan` in the app root. */
        public readonly ?string $artisanCommand = null,
        /** A directory with route-list.json and model-show/*.json, exported beforehand. Excludes artisanCommand. */
        public readonly ?string $exportDir = null,
        public readonly int $maxDepth = 20,
        public readonly ?string $viewPath = null,
    ) {
    }
}
