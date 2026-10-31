<?php

declare(strict_types=1);

return [
    'description' => 'chunk() reads in windows: queries grow with N, memory does not.',
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
