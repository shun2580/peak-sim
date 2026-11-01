<?php

declare(strict_types=1);

use PeakSim\Tests\Support\MiniAppExport as E;

return E::build(
    routes: [
        E::route('/promoted-eager', 'App\\Http\\Controllers\\TestController@promotedEager'),
        E::route('/prop-eager', 'App\\Http\\Controllers\\TestController@propEager'),
        E::route('/promoted-eager-map-new', 'App\\Http\\Controllers\\TestController@promotedEagerMapNew'),
        E::route('/promoted-eager-make', 'App\\Http\\Controllers\\TestController@promotedEagerMake'),
        E::route('/nested-eager', 'App\\Http\\Controllers\\TestController@nestedEager'),
        E::route('/promoted-lazy', 'App\\Http\\Controllers\\TestController@promotedLazy'),
        E::route('/prop-lazy', 'App\\Http\\Controllers\\TestController@propLazy'),
        E::route('/promoted-other-relation', 'App\\Http\\Controllers\\TestController@promotedOtherRelation'),
        E::route('/nested-shallow', 'App\\Http\\Controllers\\TestController@nestedShallow'),
        E::route('/other-order', 'App\\Http\\Controllers\\TestController@otherOrder'),
        E::route('/wrapped-second', 'App\\Http\\Controllers\\TestController@wrappedSecond'),
        E::route('/buyer', 'App\\Http\\Controllers\\TestController@buyer'),
    ],
    models: [
        ...E::standardModels(),
    ],
);
