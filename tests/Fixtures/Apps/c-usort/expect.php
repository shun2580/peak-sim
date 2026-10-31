<?php

declare(strict_types=1);

return [
    'description' => 'usort() calls its comparator per element, and the comparator may query.',
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
