<?php

declare(strict_types=1);

use PeakSim\Tests\Support\MiniAppExport as E;

return E::build(
    routes: [
        E::route('/t', 'App\\Http\\Controllers\\TestController@index'),
    ],
    models: [
        ...E::standardModels(),
        E::model('App\\Models\\Order', 'orders', ['customer' => ['BelongsTo', 'App\\Models\\Customer'], 'items' => ['HasMany', 'App\\Models\\Item']], events: ['saved'], observers: ['App\\Observers\\OrderObserver']),
    ],
);
