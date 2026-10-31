<?php

declare(strict_types=1);

return [
    'description' => 'A static method that is neither on the model nor a scope is unknown (a macro, maybe).',
    'routes' => [
        '/t' => [
            'query' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => null],
            'memory' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => null],
            'confidence' => null,
            'gaps_include' => ['UNKNOWN_BUILDER_METHOD'],
            'events_found' => '>=1',
        ],
    ],
];
