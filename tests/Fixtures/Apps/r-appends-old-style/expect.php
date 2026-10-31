<?php

declare(strict_types=1);

return [
    'description' => 'An old-style accessor (getXxxAttribute) listed in $appends costs a query per serialised model.',
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
