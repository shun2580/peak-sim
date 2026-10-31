<?php

declare(strict_types=1);

return [
    'description' => 'A provider that is listed in config/app.php (not in bootstrap/providers.php) is read too.',
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
