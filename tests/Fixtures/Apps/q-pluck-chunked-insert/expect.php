<?php

declare(strict_types=1);

return [
    'description' => 'Splitting the rows by hand into batches does not make an insert constant: one INSERT per batch.',
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
