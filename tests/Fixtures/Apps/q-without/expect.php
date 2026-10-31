<?php

declare(strict_types=1);

return [
    'description' => 'without() removes an eager load that $with would have added, bringing the N+1 back.',
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
