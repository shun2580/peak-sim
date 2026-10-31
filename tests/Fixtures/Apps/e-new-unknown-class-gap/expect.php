<?php

declare(strict_types=1);

return [
    'description' => 'new of a class that is neither in the app nor known is a gap.',
    'routes' => [
        '/t' => [
            'query' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => null],
            'memory' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => null],
            'confidence' => null,
            'gaps_include' => ['NEW_UNKNOWN_CLASS'],
            'events_found' => '>=1',
        ],
    ],
];
