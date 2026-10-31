<?php

declare(strict_types=1);

return [
    'description' => 'A view that only echoes reads no relation.',
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
