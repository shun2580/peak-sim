<?php

declare(strict_types=1);

return [
    'description' => 'A model with $with = [one relation] adds one extra query per request, not per row.',
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
