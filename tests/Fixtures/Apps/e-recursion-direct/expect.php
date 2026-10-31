<?php

declare(strict_types=1);

return [
    'description' => 'A method that calls itself is cut off with a RECURSION gap.',
    'routes' => [
        '/t' => [
            'query' => ['growth' => 'unknown', 'lower_bound' => null, 'confidence' => null],
            'memory' => ['growth' => 'unknown', 'lower_bound' => 'O(N)', 'confidence' => null],
            'confidence' => null,
            'gaps' => ['RECURSION'],
            'events_found' => '>=1',
        ],
    ],
];
