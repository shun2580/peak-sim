<?php

declare(strict_types=1);

return [
    'description' => '@include with a literal name is followed into the partial.',
    'routes' => [
        '/t' => [
            'query' => 'O(N)',
            'memory' => 'O(N)',
            'confidence' => 'high',
            'n_table' => 'orders',
            'gaps' => [],
            'events_found' => '>=1',
            'evidence_include' => ['resources/views/partials/row.blade.php:1'],
        ],
    ],
];
