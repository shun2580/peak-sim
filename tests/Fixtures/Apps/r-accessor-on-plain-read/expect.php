<?php

declare(strict_types=1);

return [
    'description' => 'An accessor also runs when its attribute is simply read, with no serialisation and no $appends.',
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
