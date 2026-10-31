<?php

declare(strict_types=1);

return [
    'description' => 'cursor() on SQLite: nothing is buffered, so memory is flat.',
    'routes' => [
        '/t' => [
            'query' => 'O(1)',
            'memory' => 'O(1)',
            'confidence' => 'high',
            'n_table' => 'orders',
            'gaps' => [],
            'events_found' => '>=1',
        ],
    ],
];
