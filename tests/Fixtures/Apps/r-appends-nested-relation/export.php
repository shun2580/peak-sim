<?php

declare(strict_types=1);

use PeakSim\Tests\Support\MiniAppExport as E;

return E::build(
    routes: [
        E::route('/t', 'App\\Http\\Controllers\\TestController@index'),
    ],
    models: [
        ...E::standardModels(),
        E::model('App\\Models\\NestedOrder', 'orders', ['customer' => ['BelongsTo', 'App\\Models\\AppendedCustomer'], 'items' => ['HasMany', 'App\\Models\\Item']]),
        E::model('App\\Models\\AppendedCustomer', 'customers', ['orders' => ['HasMany', 'App\\Models\\Order']]),
    ],
);
