<?php

declare(strict_types=1);

return [
    'description' => 'A facade call that is not on the allow-list is a gap, even if the rest of the route is harmless.',
    'routes' => [
        '/t' => [
            'query' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => null],
            'memory' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => null],
            'confidence' => null,
            'gaps_include' => ['VENDOR_CALL_NOT_MODELLED'],
            'events_found' => '>=1',
        ],
    ],
];
