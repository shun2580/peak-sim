<?php

declare(strict_types=1);

return [
    'description' => 'paginate() is bounded: O(1) queries and O(1) memory.',
    'routes' => [
        '/t' => [
            'query' => 'O(1)',
            'memory' => 'O(1)',
            'confidence' => 'high',
            'n_table' => 'orders',
            'gaps' => [],
            'events_found' => '>=1',
            'assumptions' => [],
        ],
    ],
];
