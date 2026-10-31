<?php

declare(strict_types=1);

return [
    'description' => 'An @include whose name is a variable cannot be followed.',
    'routes' => [
        '/t' => [
            'query' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => null],
            'memory' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => 'O(N)'],
            'confidence' => null,
            'gaps' => ['VIEW_NOT_FOUND'],
            'events_found' => '>=1',
        ],
    ],
];
