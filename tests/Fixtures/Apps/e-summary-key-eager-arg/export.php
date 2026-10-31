<?php

declare(strict_types=1);

use PeakSim\Tests\Support\MiniAppExport as E;

return E::build(
    routes: [
        E::route('/a-lazy', 'App\\Http\\Controllers\\TestController@lazy'),
        E::route('/b-eager', 'App\\Http\\Controllers\\TestController@eager'),
        E::route('/c-both', 'App\\Http\\Controllers\\TestController@both'),
    ],
    models: [
        ...E::standardModels(),
    ],
);
