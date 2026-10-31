<?php

declare(strict_types=1);

return [
    'description' => 'whenLoaded() never triggers a lazy load: with no eager load the resource is safe.',
    'routes' => [
        '/t' => [
            'query' => 'O(1)',
            'memory' => 'O(N)',
            'confidence' => 'high',
            'n_table' => 'orders',
            'gaps' => [],
            'events_found' => '>=1',
        ],
    ],
];
