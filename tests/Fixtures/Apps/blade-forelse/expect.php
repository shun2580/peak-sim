<?php

declare(strict_types=1);

return [
    'description' => '@forelse walks the loop body like @foreach (the @empty branch is a plain block).',
    'routes' => [
        '/t' => [
            'query' => 'O(N)',
            'memory' => 'O(N)',
            'confidence' => 'high',
            'n_table' => 'orders',
            'gaps' => [],
            'events_found' => '>=1',
            'evidence_include' => ['resources/views/t.blade.php:2'],
        ],
    ],
];
