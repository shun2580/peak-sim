<?php

declare(strict_types=1);

return [
    'description' => 'A cache lookup per iteration: the miss is an N+1, the hit reads no relation, and what is cached is a scalar.',
    'routes' => [
        '/t' => [
            'query' => 'O(N)',
            'memory' => 'O(N)',
            'confidence' => 'high',
            'n_table' => 'orders',
            'gaps' => [],
            'events_found' => '>=1',
            'cache' => [
                'on_miss' => ['query' => 'O(N)', 'memory' => 'O(N)'],
                'on_hit' => ['query' => 'O(1)', 'memory' => 'O(N)'],
                'restores_all_on_hit' => false,
            ],
        ],
    ],
];
