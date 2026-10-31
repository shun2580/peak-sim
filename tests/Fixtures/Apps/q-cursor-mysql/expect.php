<?php

declare(strict_types=1);

return [
    'description' => 'cursor() on MySQL: one query, but the driver buffers every row, so memory grows with N.',
    'routes' => [
        '/t' => [
            'query' => 'O(1)',
            'memory' => 'O(N)',
            'confidence' => 'high',
            'n_table' => 'orders',
            'gaps' => [],
            'events_found' => '>=1',
        ],
    ],
];
