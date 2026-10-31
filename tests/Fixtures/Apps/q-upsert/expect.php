<?php

declare(strict_types=1);

return [
    'description' => 'upsert() with one row per customer is one statement carrying N rows.',
    'routes' => [
        '/t' => [
            'query' => 'O(1)',
            'memory' => 'O(N)',
            'confidence' => 'high',
            'n_table' => 'customers',
            'gaps' => [],
            'events_found' => '>=1',
        ],
    ],
];
