<?php

declare(strict_types=1);

return [
    'description' => 'A closure passed alone to bind() binds the class its return type names; the class it returns is the implementation.',
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
