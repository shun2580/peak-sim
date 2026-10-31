<?php

declare(strict_types=1);

return [
    'description' => 'A binding that is decorated with extend() cannot be followed to one implementation.',
    'routes' => [
        '/t' => [
            'query' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => null],
            'memory' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => null],
            'confidence' => null,
            'gaps_include' => ['BINDING_DECORATED'],
        ],
    ],
];
