<?php

declare(strict_types=1);

return [
    'description' => 'Collecting the items of every order into one array.',
    'routes' => [
        '/t' => [
            'query' => 'O(N)',
            'memory' => 'O(N*M)',
            'confidence' => 'high',
            'n_table' => 'orders',
            'gaps' => [],
            'events_found' => '>=1',
            'm_table' => 'items',
            'm_per' => 'orders',
        ],
    ],
];
