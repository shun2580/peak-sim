<?php

declare(strict_types=1);

return [
    'description' => 'Cache::remember() around a scalar: both sides are constant.',
    'routes' => [
        '/t' => [
            'query' => 'O(1)',
            'memory' => 'O(1)',
            'confidence' => 'high',
            'n_table' => 'orders',
            'gaps' => [],
            'events_found' => '>=1',
            'cache' => [
                'on_miss' => ['query' => 'O(1)', 'memory' => 'O(1)'],
                'on_hit' => ['query' => 'O(1)', 'memory' => 'O(1)'],
                'restores_all_on_hit' => false,
            ],
        ],
    ],
];
