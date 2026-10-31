<?php

declare(strict_types=1);

return [
    'description' => '@php ... @endphp blocks are evaluated like PHP.',
    'routes' => [
        '/t' => [
            'query' => 'O(N)',
            'memory' => 'O(N)',
            'confidence' => 'high',
            'n_table' => 'orders',
            'gaps' => [],
            'events_found' => '>=1',
        ],
    ],
];
