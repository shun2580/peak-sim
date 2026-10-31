<?php

declare(strict_types=1);

return [
    'description' => 'A Blade component (<x-...>) is outside the supported set.',
    'routes' => [
        '/t' => [
            'query' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => null],
            'memory' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => 'O(N)'],
            'confidence' => null,
            'gaps' => ['BLADE_UNSUPPORTED'],
            'events_found' => '>=1',
        ],
    ],
];
