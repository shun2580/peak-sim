<?php

declare(strict_types=1);

return [
    'description' => 'flatMap() over a to-many relation: one query per order, and the items multiply memory.',
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
