<?php

declare(strict_types=1);

return [
    'description' => 'A contextual binding (when/needs/give) is chosen by the class that asks for the interface.',
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
            'evidence_include' => ['app/Readers/LazyOrderReader.php'],
        ],
    ],
];
