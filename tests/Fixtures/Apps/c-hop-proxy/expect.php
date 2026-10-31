<?php

declare(strict_types=1);

return [
    'description' => 'A higher-order proxy ($orders->each->save()) is a loop with a statement per element.',
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
