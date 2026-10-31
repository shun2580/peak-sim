<?php

declare(strict_types=1);

return [
    'description' => 'Raw SQL is not parsed: the table is unknown and the size is assumed unbounded.',
    'routes' => [
        '/t' => [
            'query' => 'O(1)',
            'memory' => ['growth' => 'O(N)', 'confidence' => 'medium'],
            'confidence' => 'medium',
            'n_table' => null,
            'gaps' => [],
            'events_found' => '>=1',
            'assumptions_include' => ['SQL_NOT_PARSED'],
        ],
    ],
];
