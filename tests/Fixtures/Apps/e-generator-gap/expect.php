<?php

declare(strict_types=1);

return [
    'description' => 'A method that yields is a generator: its body runs lazily, and the tool does not analyse it.',
    'routes' => [
        '/t' => [
            'query' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => null],
            'memory' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => null],
            'confidence' => null,
            'gaps_include' => ['GENERATOR_NOT_ANALYZED'],
        ],
    ],
];
