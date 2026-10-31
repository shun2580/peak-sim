<?php

declare(strict_types=1);

return [
    'description' => 'A resource that nests another resource over a to-many relation.',
    'routes' => [
        '/t' => [
            'query' => 'O(N*M)',
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
