<?php

declare(strict_types=1);

return [
    'description' => 'N+1 wrapped in Cache::remember: the miss costs O(N) queries, the hit O(1), and the hit still restores every row.',
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
