<?php

declare(strict_types=1);

return [
    'description' => 'An interface with no binding at all cannot be followed: both axes are unknown, never O(1).',
    'routes' => [
        '/t' => [
            'query' => ['growth' => 'unknown', 'lower_bound' => null, 'confidence' => null],
            'memory' => ['growth' => 'unknown', 'lower_bound' => null, 'confidence' => null],
            'confidence' => null,
            'gaps' => ['NO_BINDING'],
            'events_found' => 0,
        ],
    ],
];
