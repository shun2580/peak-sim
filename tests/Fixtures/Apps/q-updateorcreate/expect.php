<?php

declare(strict_types=1);

return [
    'description' => 'updateOrCreate() inside a loop is one or two statements per iteration.',
    'routes' => [
        '/t' => [
            'query' => 'O(N)',
            'memory' => 'O(N)',
            'confidence' => 'high',
            'n_table' => 'customers',
            'gaps' => [],
            'events_found' => '>=1',
        ],
    ],
];
