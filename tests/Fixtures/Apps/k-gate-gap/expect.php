<?php

declare(strict_types=1);

return [
    'description' => 'Authorisation goes through the gate, which can run policy code with queries: not modelled, so a gap.',
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
