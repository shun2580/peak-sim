<?php

declare(strict_types=1);

return [
    'description' => 'A closure bound by its return type whose body picks the class from a config value cannot be followed: both axes are unknown, never O(1).',
    'routes' => [
        '/t' => [
            'query' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => null],
            'memory' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => null],
            'confidence' => null,
            'gaps_include' => ['UNRESOLVABLE_BINDING'],
        ],
    ],
];
