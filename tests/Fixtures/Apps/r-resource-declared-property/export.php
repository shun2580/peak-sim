<?php

declare(strict_types=1);

use PeakSim\Tests\Support\MiniAppExport as E;

return E::build(
    routes: [
        E::route('/plain-collection', 'App\\Http\\Controllers\\TestController@plainCollection'),
        E::route('/promoted-via', 'App\\Http\\Controllers\\TestController@promotedVia'),
        E::route('/unless-flag-plain', 'App\\Http\\Controllers\\TestController@unlessFlagPlain'),
        E::route('/accessor-direct', 'App\\Http\\Controllers\\TestController@accessorDirect'),
        E::route('/promoted-collection', 'App\\Http\\Controllers\\TestController@promotedCollection'),
        E::route('/prop', 'App\\Http\\Controllers\\TestController@prop'),
        E::route('/local-var', 'App\\Http\\Controllers\\TestController@localVar'),
        E::route('/unless-flag', 'App\\Http\\Controllers\\TestController@unlessFlag'),
        E::route('/accessor', 'App\\Http\\Controllers\\TestController@accessor'),
        E::route('/promoted-map-new', 'App\\Http\\Controllers\\TestController@promotedMapNew'),
        E::route('/promoted-make', 'App\\Http\\Controllers\\TestController@promotedMake'),
        E::route('/promoted-new', 'App\\Http\\Controllers\\TestController@promotedNew'),
    ],
    models: [
        ...E::standardModels(),
    ],
);
