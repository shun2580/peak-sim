<?php

declare(strict_types=1);

return [
    'description' => 'A binding that points at another abstraction (A -> B -> concrete class) is followed to the end.',
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
            'evidence_include' => ['app/Readers/EloquentOrderSource.php'],
        ],
    ],
];
