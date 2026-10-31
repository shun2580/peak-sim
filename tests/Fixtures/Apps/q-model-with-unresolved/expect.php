<?php

declare(strict_types=1);

return [
    'description' => 'A model whose $with cannot be read (a constant the tool cannot evaluate) must not be treated as having none.',
    'routes' => [
        '/t' => [
            'query' => ['growth' => 'unknown', 'confidence' => null],
            'memory' => ['growth' => 'unknown', 'confidence' => null],
            'confidence' => null,
            'gaps_include' => ['MODEL_PROPERTY_UNRESOLVED'],
            'events_found' => '>=1',
        ],
    ],
];
