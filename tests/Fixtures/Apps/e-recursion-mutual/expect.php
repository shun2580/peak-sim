<?php

declare(strict_types=1);

return [
    'description' => 'Two methods that call each other are cut off with a RECURSION gap.',
    'routes' => [
        '/t' => [
            'query' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => null],
            'memory' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => 'O(N)'],
            'confidence' => null,
            'gaps' => ['RECURSION'],
            'events_found' => '>=1',
        ],
    ],
];
