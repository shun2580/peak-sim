<?php

declare(strict_types=1);

return [
    'description' => 'Collecting every row of every chunk into one array defeats chunk(): memory is O(N).',
    'routes' => [
        '/t' => [
            'query' => ['growth' => 'O(N)', 'confidence' => 'high'],
            'memory' => ['growth' => 'O(N)', 'confidence' => 'medium'],
            'confidence' => 'medium',
            'n_table' => 'orders',
            'gaps' => [],
            'events_found' => '>=1',
            'assumptions_include' => ['ACCUMULATION_HEURISTIC'],
        ],
    ],
];
