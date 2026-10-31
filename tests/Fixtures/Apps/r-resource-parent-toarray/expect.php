<?php

declare(strict_types=1);

return [
    'description' => 'A resource that delegates to parent::toArray() serialises the model, accessors included.',
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
