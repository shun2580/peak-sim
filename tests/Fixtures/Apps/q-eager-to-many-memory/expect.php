<?php

declare(strict_types=1);

return [
    'description' => 'Eager-loading a to-many relation keeps queries flat but multiplies memory: every order carries its items.',
    'routes' => [
        '/t' => [
            'query' => 'O(1)',
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
