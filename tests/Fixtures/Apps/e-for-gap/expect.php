<?php

declare(strict_types=1);

return [
    'description' => 'A for loop is not followed: a query inside it makes both axes unknown.',
    'routes' => [
        '/t' => [
            'query' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => null],
            'memory' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => null],
            'confidence' => null,
            'gaps' => ['LOOP_BOUND_UNKNOWN'],
            'events_found' => '>=1',
        ],
    ],
];
