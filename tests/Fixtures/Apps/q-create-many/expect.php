<?php

declare(strict_types=1);

return [
    'description' => 'createMany() on a relation runs one INSERT per model it is given.',
    'routes' => [
        '/t' => [
            'query' => 'O(N)',
            'memory' => 'O(N)',
            'confidence' => 'high',
            'n_table' => 'customers',
            'gaps' => [],
            'events_found' => '>=1',
        ],
    ],
];
