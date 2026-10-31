<?php

declare(strict_types=1);

return [
    'description' => 'A for loop is not followed: a query inside it makes both axes unknown.',
    'routes' => [
        '/t' => [
            'query' => ['growth' => 'unknown', 'lower_bound' => null, 'confidence' => null],
            'memory' => ['growth' => 'unknown', 'lower_bound' => null, 'confidence' => null],
            'confidence' => null,
            'gaps' => ['LOOP_BOUND_UNKNOWN'],
            'events_found' => '>=1',
        ],
    ],
];
