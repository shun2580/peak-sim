<?php

declare(strict_types=1);

use PeakSim\Tests\Support\MiniAppExport as E;

return E::build(
    routes: [
        E::route('/untyped-prop', 'App\\Http\\Controllers\\TestController@untypedProp'),
        E::route('/vendor-prop', 'App\\Http\\Controllers\\TestController@vendorProp'),
        E::route('/scalar-chain', 'App\\Http\\Controllers\\TestController@scalarChain'),
        E::route('/scalar-chain-plain', 'App\\Http\\Controllers\\TestController@scalarChainPlain'),
    ],
    models: [
        ...E::standardModels(),
    ],
);
