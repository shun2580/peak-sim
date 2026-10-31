<?php

declare(strict_types=1);

return [
    'description' => 'A local scope (scopeRecent) is followed through its body.',
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
