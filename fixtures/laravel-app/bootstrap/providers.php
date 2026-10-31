<?php

use App\Hidden\Providers\ConditionalBindingServiceProvider;
use App\Ng\Providers\LayeredServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    LayeredServiceProvider::class,
    ConditionalBindingServiceProvider::class,
];
