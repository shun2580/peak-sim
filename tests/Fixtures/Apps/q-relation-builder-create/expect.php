<?php

declare(strict_types=1);

return [
    'description' => 'Creating through a relation builder inside a loop is a statement per iteration.',
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
