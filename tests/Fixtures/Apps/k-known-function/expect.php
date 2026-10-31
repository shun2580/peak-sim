<?php

declare(strict_types=1);

return [
    'description' => 'Plain helpers (now, round, count, in_array) are known and create no gap.',
    'routes' => [
        '/t' => [
            'query' => 'O(1)',
            'memory' => 'O(1)',
            'confidence' => 'high',
            'n_table' => 'orders',
            'gaps' => [],
            'events_found' => '>=1',
            'assumptions' => [],
        ],
    ],
];
