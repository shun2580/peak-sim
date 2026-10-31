<?php

declare(strict_types=1);

return [
    'description' => 'An interface bound with singleton() is resolved exactly like bind().',
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
            'evidence_include' => ['app/Readers/EloquentOrderReader.php'],
        ],
    ],
];
