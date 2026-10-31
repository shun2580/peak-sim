<?php

declare(strict_types=1);

return [
    'description' => 'A chain of 21 calls from the route action to the N+1.',
    'routes' => [
        '/t' => [
            'query' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => null],
            'memory' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => null],
            'confidence' => null,
            'gaps' => ['DEPTH_LIMIT'],
            'events_found' => 0,
            'truncated' => true,
        ],
    ],
];
