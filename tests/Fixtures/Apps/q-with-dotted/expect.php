<?php

declare(strict_types=1);

return [
    'description' => 'A dotted eager load is one query per level, however many rows there are.',
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
