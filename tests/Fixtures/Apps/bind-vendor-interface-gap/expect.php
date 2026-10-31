<?php

declare(strict_types=1);

return [
    'description' => 'An interface that belongs to the framework (not the app) has no binding the tool can read.',
    'routes' => [
        '/t' => [
            'query' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => null],
            'memory' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => null],
            'confidence' => null,
            'gaps_any' => ['NO_BINDING', 'VENDOR_CALL_NOT_MODELLED'],
        ],
    ],
];
