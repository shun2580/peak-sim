<?php

declare(strict_types=1);

return [
    'description' => 'A ternary is a branch like if/else: the worse side counts.',
    'routes' => [
        '/t' => [
            'query' => ['growth' => 'O(N)', 'confidence' => 'medium'],
            'memory' => ['growth' => 'O(N)', 'confidence' => 'medium'],
            'confidence' => 'medium',
            'n_table' => 'orders',
            'gaps' => [],
            'events_found' => '>=1',
            'assumptions_include' => ['BRANCH_WORST_CASE'],
        ],
    ],
];
