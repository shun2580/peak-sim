<?php

declare(strict_types=1);

return [
    'description' => 'Two models whose $with point at each other would load forever: the tool stops with a gap.',
    'routes' => [
        '/t' => [
            'query' => ['growth' => 'unknown', 'confidence' => null],
            'memory' => ['growth' => 'unknown', 'confidence' => null],
            'confidence' => null,
            'gaps_include' => ['WITH_CYCLE'],
            'events_found' => '>=1',
        ],
    ],
];
