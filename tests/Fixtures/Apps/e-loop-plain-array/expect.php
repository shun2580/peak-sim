<?php

declare(strict_types=1);

return [
    'description' => 'A foreach over a literal list is bounded: no growth, no gap.',
    'routes' => [
        '/t' => [
            'query' => ['growth' => 'O(1)', 'confidence' => 'medium'],
            'memory' => ['growth' => 'O(1)', 'confidence' => 'medium'],
            'confidence' => 'medium',
            'n_table' => 'orders',
            'gaps' => [],
            'events_found' => '>=1',
            'assumptions_include' => ['FIND_ARGUMENT_ASSUMED_SCALAR'],
        ],
    ],
];
