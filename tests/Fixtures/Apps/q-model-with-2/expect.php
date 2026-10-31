<?php

declare(strict_types=1);

return [
    'description' => 'A model with $with = [two relations] adds two extra queries; the to-many one multiplies memory.',
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
