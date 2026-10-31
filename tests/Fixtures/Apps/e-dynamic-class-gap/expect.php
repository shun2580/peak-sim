<?php

declare(strict_types=1);

return [
    'description' => 'new $class with a class name from the request is a gap.',
    'routes' => [
        '/t' => [
            'query' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => null],
            'memory' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => null],
            'confidence' => null,
            'gaps_include' => ['DYNAMIC_CLASS_NAME'],
            'events_found' => '>=1',
        ],
    ],
];
