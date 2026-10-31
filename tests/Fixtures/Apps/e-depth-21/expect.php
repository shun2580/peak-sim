<?php

declare(strict_types=1);

return [
    'description' => 'A chain of 21 calls from the route action to the N+1.',
    'routes' => [
        '/t' => [
            'query' => ['growth' => 'unknown', 'lower_bound' => null, 'confidence' => null],
            'memory' => ['growth' => 'unknown', 'lower_bound' => null, 'confidence' => null],
            'confidence' => null,
            'gaps' => ['DEPTH_LIMIT'],
            'events_found' => 0,
            'truncated' => true,
        ],
    ],
];
