<?php

declare(strict_types=1);

return [
    'description' => 'chunkById() reads in windows like chunk().',
    'routes' => [
        '/t' => [
            'query' => 'O(N)',
            'memory' => 'O(1)',
            'confidence' => 'high',
            'n_table' => 'orders',
            'gaps' => [],
            'events_found' => '>=1',
        ],
    ],
];
