<?php

declare(strict_types=1);

return [
    'description' => 'rememberForever() is Cache::remember() without an expiry.',
    'routes' => [
        '/t' => [
            'query' => 'O(N)',
            'memory' => 'O(N)',
            'confidence' => 'high',
            'n_table' => 'orders',
            'gaps' => [],
            'events_found' => '>=1',
            'assumptions_include' => ['CACHE_DRIVER_SERIALIZING'],
            'cache' => [
                'on_miss' => ['query' => 'O(N)', 'memory' => 'O(N)'],
                'on_hit' => ['query' => 'O(1)', 'memory' => 'O(N)'],
                'restores_all_on_hit' => true,
            ],
        ],
    ],
];
