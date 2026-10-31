<?php

declare(strict_types=1);

return [
    'description' => 'A foreach over an array that comes from the request has no known size: gap.',
    'routes' => [
        '/t' => [
            'query' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => null],
            'memory' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => null],
            'confidence' => null,
            'gaps' => ['LOOP_SOURCE_UNKNOWN'],
            'events_found' => '>=1',
        ],
    ],
];
