<?php

declare(strict_types=1);

return [
    'description' => 'event() runs listeners the tool cannot see: a gap.',
    'routes' => [
        '/t' => [
            'query' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => null],
            'memory' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => null],
            'confidence' => null,
            'gaps_any' => ['UNRESOLVED_CALL', 'VENDOR_CALL_NOT_MODELLED'],
            'events_found' => '>=1',
        ],
    ],
];
