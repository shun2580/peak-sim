<?php

declare(strict_types=1);

return [
    'description' => 'A Collection method that does not exist may be a macro: unknown.',
    'routes' => [
        '/t' => [
            'query' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => null],
            'memory' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => 'O(N)'],
            'confidence' => null,
            'gaps' => ['UNKNOWN_COLLECTION_METHOD'],
            'events_found' => '>=1',
        ],
    ],
];
