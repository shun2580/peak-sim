<?php

declare(strict_types=1);

return [
    'description' => 'items()->count() (with parentheses) is a query per order, unlike the items attribute.',
    'routes' => [
        '/t' => [
            'query' => 'O(N)',
            'memory' => 'O(N)',
            'confidence' => 'high',
            'n_table' => 'orders',
            'gaps' => [],
            'events_found' => '>=1',
        ],
    ],
];
