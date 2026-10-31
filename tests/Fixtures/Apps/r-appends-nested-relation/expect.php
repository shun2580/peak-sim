<?php

declare(strict_types=1);

return [
    'description' => 'An accessor on a model reached through an eager-loaded relation still runs for every row.',
    'routes' => [
        '/t' => [
            'query' => 'O(N)',
            'confidence' => 'high',
            'n_table' => 'orders',
            'gaps' => [],
            'events_found' => '>=1',
        ],
    ],
];
