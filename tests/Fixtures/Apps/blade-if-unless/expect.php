<?php

declare(strict_types=1);

return [
    'description' => 'Conditionals around the planted read do not hide it: @if, @unless and @isset are walked.',
    'routes' => [
        '/t' => [
            'query' => 'O(N)',
            'memory' => 'O(N)',
            'confidence' => 'high',
            'n_table' => 'orders',
            'gaps' => [],
            'events_found' => '>=1',
        ],
    ],
];
