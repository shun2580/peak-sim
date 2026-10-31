<?php

declare(strict_types=1);

return [
    'description' => 'A chain of 20 calls from the route action to the N+1.',
    'routes' => [
        '/t' => [
            'query' => 'O(N)',
            'memory' => 'O(N)',
            'confidence' => 'high',
            'n_table' => 'orders',
            'gaps' => [],
            'events_found' => '>=1',
            'truncated' => false,
        ],
    ],
];
