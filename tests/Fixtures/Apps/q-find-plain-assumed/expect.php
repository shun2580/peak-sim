<?php

declare(strict_types=1);

return [
    'description' => 'find() with an argument that may be one id or an array of ids is taken as one row, and says so.',
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
