<?php

declare(strict_types=1);

use PeakSim\Tests\Support\MiniAppExport as E;

return E::build(
    routes: [
        E::route('/t', 'App\\Http\\Controllers\\TestController@index'),
    ],
    models: [
        ...E::standardModels(),
    ],
);
