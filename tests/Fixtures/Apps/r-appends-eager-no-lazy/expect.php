<?php

declare(strict_types=1);

return [
    'description' => 'The same accessor over models that were loaded with their customer reads nothing more.',
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
