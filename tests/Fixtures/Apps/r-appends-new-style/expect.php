<?php

declare(strict_types=1);

return [
    'description' => 'A model with $appends and an Attribute accessor that reads a relation costs a query per serialised model.',
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
