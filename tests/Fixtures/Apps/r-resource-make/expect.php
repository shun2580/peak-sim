<?php

declare(strict_types=1);

return [
    'description' => 'A single-model resource reads the relation once.',
    'routes' => [
        '/t' => [
            'query' => 'O(1)',
            'memory' => 'O(1)',
            'confidence' => 'high',
            'n_table' => 'orders',
            'gaps' => [],
            'events_found' => '>=1',
        ],
    ],
];
