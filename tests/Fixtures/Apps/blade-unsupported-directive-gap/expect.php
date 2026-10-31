<?php

declare(strict_types=1);

return [
    'description' => '@can calls the authorisation gate, which the tool does not model: the view becomes a gap.',
    'routes' => [
        '/t' => [
            'query' => ['growth' => 'unknown', 'lower_bound' => null, 'confidence' => null],
            'memory' => ['growth' => 'unknown', 'lower_bound' => 'O(N)', 'confidence' => null],
            'confidence' => null,
            'gaps' => ['BLADE_UNSUPPORTED'],
            'events_found' => '>=1',
        ],
    ],
];
