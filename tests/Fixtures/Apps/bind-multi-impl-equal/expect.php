<?php

declare(strict_types=1);

return [
    'description' => 'Two bindings for one interface whose implementations behave the same: no worst case to speak of.',
    'routes' => [
        '/t' => [
            'query' => 'O(N)',
            'memory' => 'O(N)',
            'confidence' => 'high',
            'n_table' => 'orders',
            'gaps' => [],
            'events_found' => '>=1',
            'assumptions' => [],
            'worst_case_adopted' => false,
        ],
    ],
];
